#!/usr/bin/env python3
"""
Cash Top Analytics - collector (stdlib only, zero dependencies).

A small self-contained HTTP service for a central cash-top host. It:
  - serves the one-line tracker           GET  /a/t.js
  - serves the vendored rrweb bundle       GET  /a/rrweb.min.js
  - serves the rrweb-player assets          GET  /a/rrweb-player.min.js / .css
  - receives batched events (CORS-safe)    POST /a/collect
  - accepts an owner page snapshot          POST /a/snapshot?site=<id>   (PNG body)
  - serves the owner dashboard              GET  /a/dashboard
  - serves dashboard JSON APIs              GET  /a/api/*
  - serves a snapshot image                 GET  /a/snapshot?site=<id>

Storage: one SQLite DB (data/analytics.db) + snapshots under data/snapshots/.
No external services, no PII beyond what the page sends (inputs are masked by
the tracker). For a drop-in PHP site, use the sibling collect.php instead.

Run:
    python3 collector.py --port 18090
Env:
    CT_ANALYTICS_PORT   override port (default 18090)
    CT_ANALYTICS_TOKEN  if set, dashboard + APIs require ?token=<v> (owner-only)
"""
import argparse
import json
import mimetypes
import os
import sqlite3
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import geo  # local GeoIP resolver (country by client IP)

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, "data")
SNAP = os.path.join(DATA, "snapshots")
DB_PATH = os.path.join(DATA, "analytics.db")
os.makedirs(SNAP, exist_ok=True)

_LOCK = threading.Lock()
ALL_SITES = "*all"  # pseudo-site: every landing together


def db():
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def init_db():
    with db() as c:
        c.executescript(
            """
            CREATE TABLE IF NOT EXISTS sessions (
                site TEXT, session TEXT, visitor TEXT,
                url TEXT, path TEXT, referrer TEXT, ua TEXT, device TEXT,
                lang TEXT, tz TEXT,
                screen_w INT, screen_h INT, viewport_w INT, viewport_h INT,
                doc_w INT, doc_h INT,
                first_ts INT, last_ts INT, time_on_page INT DEFAULT 0,
                max_scroll REAL DEFAULT 0,
                country_code TEXT DEFAULT '', country TEXT DEFAULT '',
                region TEXT DEFAULT '', city TEXT DEFAULT '',
                PRIMARY KEY (site, session)
            );
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site TEXT, session TEXT, ts INT, type TEXT, data TEXT
            );
            CREATE TABLE IF NOT EXISTS recordings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site TEXT, session TEXT, ts INT, events TEXT
            );
            CREATE INDEX IF NOT EXISTS ix_ev_site ON events(site, type);
            CREATE INDEX IF NOT EXISTS ix_ev_sess ON events(site, session);
            CREATE INDEX IF NOT EXISTS ix_rec_sess ON recordings(site, session);
            CREATE INDEX IF NOT EXISTS ix_sess_site ON sessions(site);
            """
        )


def ingest(p, geo_info=None):
    site = str(p.get("site") or "default")
    session = str(p.get("session") or "")
    if not session:
        return
    visitor = str(p.get("visitor") or "")
    meta = p.get("meta") or {}
    doc = p.get("doc") or {}
    geo_info = geo_info or {}
    now = int(time.time() * 1000)
    events = p.get("events") or []
    rr = p.get("rr") or []
    with _LOCK, db() as c:
        row = c.execute(
            "SELECT first_ts FROM sessions WHERE site=? AND session=?",
            (site, session),
        ).fetchone()
        if row is None:
            c.execute(
                """INSERT INTO sessions
                   (site,session,visitor,url,path,referrer,ua,device,lang,tz,
                    screen_w,screen_h,viewport_w,viewport_h,doc_w,doc_h,
                    first_ts,last_ts,country_code,country,region,city)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    site, session, visitor,
                    meta.get("url", ""), meta.get("path", ""),
                    meta.get("referrer", ""), meta.get("ua", ""),
                    meta.get("device", ""), meta.get("lang", ""),
                    meta.get("tz", ""),
                    int(meta.get("screen_w", 0) or 0),
                    int(meta.get("screen_h", 0) or 0),
                    int(meta.get("viewport_w", 0) or 0),
                    int(meta.get("viewport_h", 0) or 0),
                    int(doc.get("w", 0) or 0), int(doc.get("h", 0) or 0),
                    now, now,
                    geo_info.get("code", ""), geo_info.get("name", ""),
                    geo_info.get("region", ""), geo_info.get("city", ""),
                ),
            )
        else:
            c.execute(
                "UPDATE sessions SET last_ts=?, doc_w=?, doc_h=? WHERE site=? AND session=?",
                (now, int(doc.get("w", 0) or 0), int(doc.get("h", 0) or 0), site, session),
            )
        for e in events:
            etype = str(e.get("t") or "event")
            if etype == "pageview" and geo_info:
                e = dict(e)
                e["country_code"] = geo_info.get("code", "")
                e["country"] = geo_info.get("name", "")
            c.execute(
                "INSERT INTO events (site,session,ts,type,data) VALUES (?,?,?,?,?)",
                (site, session, int(e.get("ts", now)), etype, json.dumps(e)),
            )
            if etype == "timing":
                c.execute(
                    "UPDATE sessions SET time_on_page=?, max_scroll=MAX(max_scroll,?) WHERE site=? AND session=?",
                    (int(e.get("time_on_page", 0) or 0), float(e.get("max_scroll", 0) or 0), site, session),
                )
            elif etype == "scroll":
                c.execute(
                    "UPDATE sessions SET max_scroll=MAX(max_scroll,?) WHERE site=? AND session=?",
                    (float(e.get("depth", 0) or 0), site, session),
                )
        if rr:
            c.execute(
                "INSERT INTO recordings (site,session,ts,events) VALUES (?,?,?,?)",
                (site, session, now, json.dumps(rr)),
            )


# ---------------------------------------------------------------- dashboard API
_CLICK_IDS = (
    ("twclid", "X Ads"), ("rdt_cid", "Reddit Ads"), ("tblci", "Taboola"),
    ("epik", "Pinterest Ads"), ("gclid", "Google Ads"), ("fbclid", "Facebook"),
    ("ttclid", "TikTok Ads"), ("msclkid", "Microsoft Ads"),
)
_REF_HOSTS = (
    ("taboola", "Taboola"), ("x.com", "X"), ("t.co", "X"), ("twitter.", "X"),
    ("reddit.", "Reddit"), ("pinterest.", "Pinterest"), ("google.", "Google"),
    ("facebook.", "Facebook"), ("instagram.", "Instagram"), ("tiktok.", "TikTok"),
    ("bing.", "Bing"), ("yahoo.", "Yahoo"), ("youtube.", "YouTube"),
)


def attribution(url, referrer):
    """(source, creative) of a session: ad click-id / utm_source / referrer host, and the ?ref= label."""
    try:
        u = urlparse(url or "")
        q = {k: v[0] for k, v in parse_qs(u.query).items() if v}
        own = u.netloc
    except Exception:
        q, own = {}, ""
    utm = (q.get("utm_source") or "").strip()
    creative = (q.get("ref") or "").strip()
    if not creative and utm.lower().startswith("ref"):
        creative, utm = utm, ""
    if not creative:
        creative = (q.get("utm_content") or q.get("utm_campaign") or "").strip()
    source = ""
    for key, name in _CLICK_IDS:
        if q.get(key):
            source = name
            break
    if not source and utm:
        source = utm
    if not source:
        try:
            host = urlparse(referrer or "").netloc.lower()
        except Exception:
            host = ""
        if host.startswith("www."):
            host = host[4:]
        if not host:
            source = "direct"
        elif own and host == own.lower().replace("www.", ""):
            source = "internal"
        else:
            source = next((n for h, n in _REF_HOSTS if h in host), host)
    return source[:60], creative[:80]


def _site_where(site):
    return ("1=1", ()) if site == ALL_SITES else ("site=?", (site,))


def api_sites():
    with db() as c:
        rows = c.execute(
            "SELECT site, COUNT(*) FROM sessions GROUP BY site ORDER BY site"
        ).fetchall()
    out = [{"site": r[0], "sessions": r[1]} for r in rows]
    if len(out) > 1:
        out.insert(0, {"site": ALL_SITES, "sessions": sum(r["sessions"] for r in out)})
    return out


def api_overview(site):
    w, args = _site_where(site)
    with db() as c:
        sess = c.execute(
            "SELECT visitor, device, referrer, time_on_page, max_scroll, country_code, country, url, site, session FROM sessions WHERE " + w,
            args,
        ).fetchall()
        pv = c.execute(
            "SELECT COUNT(*) FROM events WHERE type='pageview' AND " + w, args
        ).fetchone()[0]
        clicks = c.execute(
            "SELECT data FROM events WHERE type='click' AND " + w, args
        ).fetchall()
        click_counts = dict(
            ((r[0], r[1]), r[2]) for r in c.execute(
                "SELECT site, session, COUNT(*) FROM events WHERE type='click' AND " + w
                + " GROUP BY site, session", args)
        )
    uniques = len({s[0] for s in sess if s[0]})
    times = [s[3] for s in sess if s[3]]
    avg_time = round(sum(times) / len(times), 1) if times else 0
    devices, referrers, countries = {}, {}, {}
    sources, creatives = {}, {}
    for s in sess:
        src, cre = attribution(s[7], s[2])
        n_clicks = click_counts.get((s[8], s[9]), 0)
        for bucket, key, extra in (
            (sources, src, {}),
            (creatives, (cre, src), {"creative": cre, "source": src}) if cre else (None, None, None),
        ):
            if bucket is None:
                continue
            row = bucket.setdefault(key, dict(
                extra, label=(cre if extra else src), sessions=0, _vis=set(), _time=0,
                engaged=0, scroll50=0, clicks=0))
            row["sessions"] += 1
            row["_vis"].add(s[0])
            row["_time"] += s[3] or 0
            row["scroll50"] += 1 if float(s[4] or 0) >= 0.5 else 0
            row["clicks"] += n_clicks
            row["engaged"] += 1 if n_clicks else 0
        devices[s[1] or "unknown"] = devices.get(s[1] or "unknown", 0) + 1
        ref = s[2] or "direct"
        try:
            if ref and ref != "direct":
                ref = urlparse(ref).netloc or ref
        except Exception:
            pass
        referrers[ref] = referrers.get(ref, 0) + 1
        cc = s[5] or ""
        cname = s[6] or (cc if cc else "Unknown")
        key = cc or "??"
        row = countries.setdefault(key, {"code": cc, "name": cname, "count": 0})
        row["count"] += 1
    # scroll depth distribution (buckets of 10%)
    buckets = [0] * 11
    for s in sess:
        d = min(1.0, float(s[4] or 0))
        buckets[int(d * 10)] += 1
    # top clicked elements
    top = {}
    for (data,) in clicks:
        try:
            e = json.loads(data)
        except Exception:
            continue
        key = e.get("sel") or e.get("tag") or "?"
        if e.get("text"):
            key = (e.get("text")[:40]) + "  [" + (e.get("tag") or "") + "]"
        top[key] = top.get(key, 0) + 1
    top_list = sorted(
        ({"label": k, "count": v} for k, v in top.items()),
        key=lambda x: -x["count"],
    )[:15]
    return {
        "site": site,
        "pageviews": pv,
        "sessions": len(sess),
        "uniques": uniques,
        "avg_time_on_page": avg_time,
        "devices": devices,
        "referrers": referrers,
        "sources": _attr_rows(sources),
        "creatives": _attr_rows(creatives),
        "countries": sorted(countries.values(), key=lambda x: -x["count"]),
        "scroll_buckets": buckets,
        "top_clicks": top_list,
    }


def _attr_rows(bucket):
    out = []
    for row in bucket.values():
        n = row["sessions"] or 1
        row["uniques"] = len({v for v in row.pop("_vis") if v})
        row["avg_time"] = round(row.pop("_time") / n, 1)
        out.append(row)
    return sorted(out, key=lambda x: -x["sessions"])


def api_sessions(site):
    with db() as c:
        rows = c.execute(
            """SELECT s.session, s.device, s.referrer, s.first_ts, s.last_ts,
                      s.time_on_page, s.max_scroll, s.path, s.ua,
                      (SELECT COUNT(*) FROM recordings r WHERE r.site=s.site AND r.session=s.session) AS has_rec,
                      (SELECT COUNT(*) FROM events e WHERE e.site=s.site AND e.session=s.session AND e.type='click') AS clicks,
                      s.country_code, s.country, s.url, s.site
               FROM sessions s WHERE """ + ("1=1" if site == ALL_SITES else "s.site=?")
            + " ORDER BY s.first_ts DESC LIMIT 500",
            _site_where(site)[1],
        ).fetchall()
    out = []
    for r in rows:
        src, cre = attribution(r[13], r[2])
        out.append({
            "source": src, "creative": cre, "site": r[14],
            "session": r[0], "device": r[1], "referrer": r[2] or "direct",
            "first_ts": r[3], "last_ts": r[4], "time_on_page": r[5],
            "max_scroll": r[6], "path": r[7], "ua": r[8],
            "has_recording": bool(r[9]), "clicks": r[10],
            "country_code": r[11] or "", "country": r[12] or "",
        })
    return out


def api_clicks(site):
    with db() as c:
        rows = c.execute(
            "SELECT data FROM events WHERE site=? AND type IN ('click','move')",
            (site,),
        ).fetchall()
    clicks, moves = [], []
    for (data,) in rows:
        try:
            e = json.loads(data)
        except Exception:
            continue
        pt = {"xp": e.get("xp", 0), "yp": e.get("yp", 0)}
        (clicks if e.get("t") == "click" else moves).append(pt)
    with db() as c:
        scroll = c.execute(
            "SELECT max_scroll FROM sessions WHERE site=?", (site,)
        ).fetchall()
    return {"clicks": clicks, "moves": moves,
            "scroll": [float(s[0] or 0) for s in scroll]}


def api_recording(site, session):
    with db() as c:
        rows = c.execute(
            "SELECT events FROM recordings WHERE site=? AND session=? ORDER BY id",
            (site, session),
        ).fetchall()
    events = []
    for (chunk,) in rows:
        try:
            events.extend(json.loads(chunk))
        except Exception:
            pass
    return {"events": events}


# ----------------------------------------------------------------- HTTP handler
def read_file(name):
    path = os.path.join(HERE, name)
    with open(path, "rb") as f:
        return f.read()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass  # quiet

    # ---- helpers
    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def _send(self, code, body, ctype="application/json", extra=None):
        if isinstance(body, str):
            body = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self._cors()
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _json(self, obj, code=200):
        self._send(code, json.dumps(obj), "application/json")

    def _auth_ok(self, q):
        tok = os.environ.get("CT_ANALYTICS_TOKEN")
        if not tok:
            return True
        return (q.get("token", [""])[0] == tok)

    def _asset(self, name, ctype, cache=True):
        try:
            body = read_file(name)
        except Exception:
            return self._send(404, "not found", "text/plain")
        extra = {"Cache-Control": "public, max-age=3600"} if cache else {}
        self._send(200, body, ctype, extra)

    # ---- verbs
    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        u = urlparse(self.path)
        path = u.path
        q = parse_qs(u.query)
        if path in ("/a/t.js", "/t.js"):
            return self._asset("tracker.js", "application/javascript")
        if path in ("/a/rrweb.min.js",):
            return self._asset("vendor/rrweb.min.js", "application/javascript")
        if path in ("/a/rrweb.min.css",):
            return self._asset("vendor/rrweb.min.css", "text/css")
        if path in ("/a/rrweb-player.min.js",):
            return self._asset("vendor/rrweb-player.min.js", "application/javascript")
        if path in ("/a/rrweb-player.min.css",):
            return self._asset("vendor/rrweb-player.min.css", "text/css")
        if path in ("/a/dashboard", "/a/dashboard.html", "/", "/a/"):
            if not self._auth_ok(q):
                return self._send(401, "auth required (?token=)", "text/plain")
            return self._asset("dashboard.html", "text/html", cache=False)
        if path == "/a/snapshot":
            site = q.get("site", ["default"])[0]
            fp = os.path.join(SNAP, _safe(site) + ".png")
            if os.path.exists(fp):
                with open(fp, "rb") as f:
                    return self._send(200, f.read(), "image/png")
            return self._send(404, "no snapshot", "text/plain")
        if path.startswith("/a/api/"):
            if not self._auth_ok(q):
                return self._json({"error": "auth"}, 401)
            site = q.get("site", ["default"])[0]
            if path == "/a/api/sites":
                return self._json(api_sites())
            if path == "/a/api/overview":
                return self._json(api_overview(site))
            if path == "/a/api/sessions":
                return self._json(api_sessions(site))
            if path == "/a/api/clicks":
                return self._json(api_clicks(site))
            if path == "/a/api/recording":
                return self._json(api_recording(site, q.get("session", [""])[0]))
            if path == "/a/api/has_snapshot":
                fp = os.path.join(SNAP, _safe(site) + ".png")
                return self._json({"snapshot": os.path.exists(fp)})
            return self._json({"error": "unknown api"}, 404)
        if path in ("/a/health", "/health"):
            return self._json({"ok": True})
        return self._send(404, "not found", "text/plain")

    def do_POST(self):
        u = urlparse(self.path)
        path = u.path
        q = parse_qs(u.query)
        length = int(self.headers.get("Content-Length", 0) or 0)
        raw = self.rfile.read(length) if length else b""
        if path in ("/a/collect", "/collect"):
            try:
                ip = geo.client_ip(self.headers, self.client_address[0])
                geo_info = geo.lookup(ip)  # raw IP is resolved then discarded
                ingest(json.loads(raw.decode("utf-8")), geo_info)
            except Exception as e:
                return self._json({"ok": False, "error": str(e)}, 400)
            return self._json({"ok": True})
        if path == "/a/snapshot":
            if not self._auth_ok(q):
                return self._send(401, "auth", "text/plain")
            site = q.get("site", ["default"])[0]
            with open(os.path.join(SNAP, _safe(site) + ".png"), "wb") as f:
                f.write(raw)
            return self._json({"ok": True, "bytes": len(raw)})
        return self._send(404, "not found", "text/plain")


def _safe(s):
    return "".join(ch for ch in str(s) if ch.isalnum() or ch in "-_.") or "default"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int,
                    default=int(os.environ.get("CT_ANALYTICS_PORT", "18090")))
    ap.add_argument("--host", default="127.0.0.1")
    args = ap.parse_args()
    init_db()
    srv = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"Cash Top Analytics collector on http://{args.host}:{args.port}/a/")
    print(f"  dashboard: http://{args.host}:{args.port}/a/dashboard")
    print(f"  tracker  : http://{args.host}:{args.port}/a/t.js?site=<id>")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        srv.shutdown()


if __name__ == "__main__":
    main()
