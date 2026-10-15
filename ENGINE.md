# NeuraVision — the engine (what actually runs, not just the pages)

This repo is **not a static site**. It is the full PHP backend that powers
neuracreation.com: 163 PHP files implementing auth, credits, AI generation,
a traffic-split ("bandit") engine, download delivery, ad-platform server
tracking, a self-hosted analytics collector, a support chat backend, and
admin panels. Front-end pages (`index.php`, `lp/`, `lk/`, `flow/`) are the
thin layer on top.

## How it runs

- **Language/runtime:** PHP 7.4+ behind nginx or Apache. No build step.
- **Storage:** SQLite files under `data/` (and a few module-local `.sqlite`).
  They are **created automatically on first request** — every module runs
  `CREATE TABLE IF NOT EXISTS ...` on open, so there is no schema to import.
  The live DBs are git-ignored (they hold user PII).
- **Config:** environment variables (see `.env.example`). Read from the process
  env / `$_ENV`, with a plain `api/.env` key=value fallback (`nv_api_env()`).
- **Local dev:** `cp .env.example .env`, then `php -S 127.0.0.1:8000`
  (or point php-fpm at the folder). The SQLite DBs appear under `data/` on the
  first hit. Features whose keys are empty stay dormant instead of crashing.

## Subsystems (real vs. demo)

### Real, working engine
- **Auth & accounts** (`functions/auth_db.php`, `api/auth_*.php`, `auth/`):
  email+password, Google OAuth, X (Twitter) OAuth, sessions, credit balance,
  per-account activation codes, device binding, lead capture. SQLite-backed.
- **Email codes** (`functions/email_codes.php`, `api/*_verify*.php`,
  `api/p3_trial.php`): universal verification-code table (purpose, retry limit,
  attempt counter, expiry). Used by registration and the path-3 trial gate.
  Delivery via Resend (`functions/welcome_mail.php`).
- **AI generation** (`api/generate_request.php`, `api/generation_status.php`,
  `api/venice_client.php`): **real** photo/video generation via an external
  generation API. Photo is synchronous; video / image->video / video->video
  go through an async queue and are polled to completion. Needs an API key
  (see `.env.example`).
- **Traffic-split bandit** (`lp/lp_ab.php`, `api/bandit.php`): weighted / even
  split across landing variants v1–v13 with server-side counts
  (`bandit_counts`, `split_weight_log`). Manual pin via `lp_ab_active()`.
- **Download delivery** (`api/desktop_download.php`, `api/dl_track.php`,
  `data/desktop_links.json`): signed/redirected download URLs per OS, with a
  download log. Current Windows/macOS targets live in `data/desktop_links.json`.
- **Anti-bot** (`functions/captcha.php` hCaptcha on access pages;
  `api/nv_pow.php` invisible proof-of-work on path 3).
- **Server-side ad tracking / CAPI** (`functions/x_capi.php`,
  `reddit_capi.php`, `pinterest_capi.php`, `includes/*_pixel.php`): forwards
  conversions to X / Reddit / Pinterest / Meta with click-id capture and dedup.
- **Self-hosted analytics** (`a/collect.php` + dashboard + geo lookups):
  first-party click/event collector, IP hashed with `LXTRACK_IP_SALT`.
- **Support chat** (`chat/`, `functions/support_lib.php`, Telegram bridge):
  visitor messages relayed to Telegram and polled back.
- **i18n** (`includes/nv_i18n/`): server-side ob_start translator + JS client,
  9 languages. Runtime reads the generated `nv_i18n.<lang>.php` arrays.

### Demo / lead-only (no real money or external account is touched)
- **Billing** (`api/billing_request.php`): there is **no payment provider**.
  It logs a "request to purchase" (plan + email) and pings Telegram. The old
  fake card form that could never charge anyone was removed.
- **Path-3 "Trial activated"** (`api/p3_trial.php`): collects a real email and
  verifies a code, but does **not** provision a real Pro subscription for the
  anonymous path-3 visitor — it is an anti-bot + lead-capture gate.

## Client-side keys (visible by design, restrict them)

Two Google keys ship inside page source and are therefore public on the live
site as well as here. They are not server secrets, but they should be locked
down in Google Cloud Console (HTTP-referrer restriction on the site's domains):

- **Firebase web API key** (`functions/phone_verify.php`, `verify-phone.php`) —
  phone-auth client config; override with `FIREBASE_API_KEY`.
- **PageSpeed Insights key** (`lp/v13.php`, `data-sc-k` on the site-check
  widget) — used by the in-page speed-audit tool; quota-limited.

Every real secret (bot tokens, OAuth client secrets, Resend, hCaptcha, ad
platform tokens) is read from the environment — see `.env.example`.

## Not in the repo (by design)
Secrets (`.env`), runtime SQLite DBs, user uploads, OAuth state files, and
server-side `.bak` copies are git-ignored. See `.gitignore` and `README.md`.
