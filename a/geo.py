#!/usr/bin/env python3
"""
Cash Top Analytics - GeoIP resolver (collector-side, local-first).

Resolves a client IP -> country WITHOUT calling an external API per request:
it loads a license-free IP->country dataset (sapics/ip-location-db, vendored in
geo/country-ipv4.csv + country-ipv6.csv) into sorted ranges and binary-searches.
If the local DB is missing AND the IP is public, it falls back to the free
ip-api.com with an on-disk cache keyed by a HASH of the IP.

Privacy: the raw IP is never stored. We keep only country code/name (+ optional
region/city from the fallback) and, as a cache key, a truncated SHA-256 of the IP.
"""
import csv
import hashlib
import ipaddress
import json
import os
import threading
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
GEO_DIR = os.path.join(HERE, "geo")
CACHE_PATH = os.path.join(GEO_DIR, "ipapi_cache.json")

_V4 = []  # list of (start_int, end_int, cc), sorted by start
_V6 = []
_LOADED = False
_LOCK = threading.Lock()
_CACHE = {}

COUNTRY_NAMES = {
    "AD": "Andorra", "AE": "United Arab Emirates", "AF": "Afghanistan",
    "AG": "Antigua & Barbuda", "AI": "Anguilla", "AL": "Albania", "AM": "Armenia",
    "AO": "Angola", "AR": "Argentina", "AT": "Austria", "AU": "Australia",
    "AW": "Aruba", "AZ": "Azerbaijan", "BA": "Bosnia & Herzegovina",
    "BB": "Barbados", "BD": "Bangladesh", "BE": "Belgium", "BF": "Burkina Faso",
    "BG": "Bulgaria", "BH": "Bahrain", "BI": "Burundi", "BJ": "Benin",
    "BN": "Brunei", "BO": "Bolivia", "BR": "Brazil", "BS": "Bahamas",
    "BT": "Bhutan", "BW": "Botswana", "BY": "Belarus", "BZ": "Belize",
    "CA": "Canada", "CD": "DR Congo", "CF": "Central African Rep.",
    "CG": "Congo", "CH": "Switzerland", "CI": "Côte d'Ivoire", "CL": "Chile",
    "CM": "Cameroon", "CN": "China", "CO": "Colombia", "CR": "Costa Rica",
    "CU": "Cuba", "CV": "Cabo Verde", "CY": "Cyprus", "CZ": "Czechia",
    "DE": "Germany", "DJ": "Djibouti", "DK": "Denmark", "DM": "Dominica",
    "DO": "Dominican Rep.", "DZ": "Algeria", "EC": "Ecuador", "EE": "Estonia",
    "EG": "Egypt", "ER": "Eritrea", "ES": "Spain", "ET": "Ethiopia",
    "FI": "Finland", "FJ": "Fiji", "FO": "Faroe Islands", "FR": "France",
    "GA": "Gabon", "GB": "United Kingdom", "GD": "Grenada", "GE": "Georgia",
    "GH": "Ghana", "GI": "Gibraltar", "GL": "Greenland", "GM": "Gambia",
    "GN": "Guinea", "GQ": "Eq. Guinea", "GR": "Greece", "GT": "Guatemala",
    "GU": "Guam", "GW": "Guinea-Bissau", "GY": "Guyana", "HK": "Hong Kong",
    "HN": "Honduras", "HR": "Croatia", "HT": "Haiti", "HU": "Hungary",
    "ID": "Indonesia", "IE": "Ireland", "IL": "Israel", "IM": "Isle of Man",
    "IN": "India", "IQ": "Iraq", "IR": "Iran", "IS": "Iceland", "IT": "Italy",
    "JE": "Jersey", "JM": "Jamaica", "JO": "Jordan", "JP": "Japan",
    "KE": "Kenya", "KG": "Kyrgyzstan", "KH": "Cambodia", "KI": "Kiribati",
    "KM": "Comoros", "KN": "St Kitts & Nevis", "KP": "North Korea",
    "KR": "South Korea", "KW": "Kuwait", "KY": "Cayman Islands",
    "KZ": "Kazakhstan", "LA": "Laos", "LB": "Lebanon", "LC": "St Lucia",
    "LI": "Liechtenstein", "LK": "Sri Lanka", "LR": "Liberia", "LS": "Lesotho",
    "LT": "Lithuania", "LU": "Luxembourg", "LV": "Latvia", "LY": "Libya",
    "MA": "Morocco", "MC": "Monaco", "MD": "Moldova", "ME": "Montenegro",
    "MG": "Madagascar", "MK": "North Macedonia", "ML": "Mali", "MM": "Myanmar",
    "MN": "Mongolia", "MO": "Macau", "MR": "Mauritania", "MT": "Malta",
    "MU": "Mauritius", "MV": "Maldives", "MW": "Malawi", "MX": "Mexico",
    "MY": "Malaysia", "MZ": "Mozambique", "NA": "Namibia", "NC": "New Caledonia",
    "NE": "Niger", "NG": "Nigeria", "NI": "Nicaragua", "NL": "Netherlands",
    "NO": "Norway", "NP": "Nepal", "NZ": "New Zealand", "OM": "Oman",
    "PA": "Panama", "PE": "Peru", "PF": "Fr. Polynesia", "PG": "Papua New Guinea",
    "PH": "Philippines", "PK": "Pakistan", "PL": "Poland", "PR": "Puerto Rico",
    "PS": "Palestine", "PT": "Portugal", "PY": "Paraguay", "QA": "Qatar",
    "RO": "Romania", "RS": "Serbia", "RU": "Russia", "RW": "Rwanda",
    "SA": "Saudi Arabia", "SB": "Solomon Islands", "SC": "Seychelles",
    "SD": "Sudan", "SE": "Sweden", "SG": "Singapore", "SI": "Slovenia",
    "SK": "Slovakia", "SL": "Sierra Leone", "SM": "San Marino", "SN": "Senegal",
    "SO": "Somalia", "SR": "Suriname", "SS": "South Sudan", "ST": "São Tomé",
    "SV": "El Salvador", "SY": "Syria", "SZ": "Eswatini", "TC": "Turks & Caicos",
    "TD": "Chad", "TG": "Togo", "TH": "Thailand", "TJ": "Tajikistan",
    "TL": "Timor-Leste", "TM": "Turkmenistan", "TN": "Tunisia", "TO": "Tonga",
    "TR": "Türkiye", "TT": "Trinidad & Tobago", "TW": "Taiwan", "TZ": "Tanzania",
    "UA": "Ukraine", "UG": "Uganda", "US": "United States", "UY": "Uruguay",
    "UZ": "Uzbekistan", "VA": "Vatican", "VC": "St Vincent", "VE": "Venezuela",
    "VG": "British Virgin Is.", "VI": "US Virgin Is.", "VN": "Vietnam",
    "VU": "Vanuatu", "WS": "Samoa", "YE": "Yemen", "ZA": "South Africa",
    "ZM": "Zambia", "ZW": "Zimbabwe", "XK": "Kosovo",
}


def _load_cache():
    global _CACHE
    try:
        with open(CACHE_PATH) as f:
            _CACHE = json.load(f)
    except Exception:
        _CACHE = {}


def _save_cache():
    try:
        with open(CACHE_PATH, "w") as f:
            json.dump(_CACHE, f)
    except Exception:
        pass


def _load_csv(path, store):
    if not os.path.exists(path):
        return
    with open(path, newline="") as f:
        for row in csv.reader(f):
            if len(row) < 3:
                continue
            try:
                s = int(ipaddress.ip_address(row[0]))
                e = int(ipaddress.ip_address(row[1]))
            except ValueError:
                continue
            store.append((s, e, row[2].strip().upper()))
    store.sort(key=lambda r: r[0])


def load():
    global _LOADED
    with _LOCK:
        if _LOADED:
            return
        _load_csv(os.path.join(GEO_DIR, "country-ipv4.csv"), _V4)
        _load_csv(os.path.join(GEO_DIR, "country-ipv6.csv"), _V6)
        _load_cache()
        _LOADED = True


def _bisect(store, ip_int):
    lo, hi = 0, len(store)
    while lo < hi:
        mid = (lo + hi) // 2
        if store[mid][0] <= ip_int:
            lo = mid + 1
        else:
            hi = mid
    i = lo - 1
    if 0 <= i < len(store):
        s, e, cc = store[i]
        if s <= ip_int <= e:
            return cc
    return None


def _ip_hash(ip):
    return hashlib.sha256(("ct-salt:" + ip).encode()).hexdigest()[:16]


def _is_public(ip):
    try:
        addr = ipaddress.ip_address(ip)
        return not (addr.is_private or addr.is_loopback or addr.is_reserved
                    or addr.is_link_local or addr.is_multicast)
    except ValueError:
        return False


def lookup(ip, allow_fallback=True):
    """Return dict {code, name, region, city} or {} if unknown.

    Never stores or returns the raw IP; fallback results are cached by IP hash.
    """
    if not ip:
        return {}
    load()
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        return {}
    cc = _bisect(_V6 if addr.version == 6 else _V4, int(addr))
    if cc:
        return {"code": cc, "name": COUNTRY_NAMES.get(cc, cc)}
    if not allow_fallback or not _is_public(ip):
        return {}
    key = _ip_hash(ip)
    if key in _CACHE:
        return _CACHE[key]
    result = {}
    try:
        url = ("http://ip-api.com/json/" + ip +
               "?fields=status,countryCode,country,regionName,city")
        with urllib.request.urlopen(url, timeout=3) as r:
            j = json.loads(r.read().decode())
        if j.get("status") == "success":
            result = {
                "code": j.get("countryCode", ""),
                "name": j.get("country", ""),
                "region": j.get("regionName", ""),
                "city": j.get("city", ""),
            }
    except Exception:
        result = {}
    _CACHE[key] = result
    _save_cache()
    return result


def client_ip(headers, remote_addr):
    """Extract real client IP from proxy headers, else socket peer."""
    xff = headers.get("X-Forwarded-For") or headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    xri = headers.get("X-Real-IP") or headers.get("x-real-ip")
    if xri:
        return xri.strip()
    return remote_addr


if __name__ == "__main__":
    import sys
    load()
    for ip in (sys.argv[1:] or ["8.8.8.8", "1.1.1.1", "77.88.55.77", "217.0.0.1"]):
        print(ip, "->", lookup(ip))
