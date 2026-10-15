<h1 align="center">NeuraVision</h1>
<p align="center">AI content generation platform — backend engine</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white">
  <img alt="Storage" src="https://img.shields.io/badge/storage-SQLite-003b57?logo=sqlite&logoColor=white">
  <img alt="Status" src="https://img.shields.io/badge/status-private-lightgrey">
  <img alt="License" src="https://img.shields.io/badge/license-proprietary-blue">
</p>

---

## Overview

Backend engine for the NeuraVision product: member dashboard logic, backend API,
auth, and a self-hosted analytics/traffic-split tracker. This export is **code only**
— no landing pages, marketing content, or media. See [`ENGINE.md`](ENGINE.md) for a
subsystem-by-subsystem map (what's a real working engine vs. demo/lead-only) and
[`.env.example`](.env.example) for configuration.

## Contents

| Path | What's there |
|---|---|
| `api/` | Backend endpoints — downloads, generation requests, OAuth, billing |
| `functions/` | Shared PHP helpers (auth, email, SMS, captcha, reviews) |
| `lk/` | Member dashboard backend logic |
| `auth/`, `auth.php`, `register.php`, `logout.php` | Google / Twitter OAuth, login/registration |
| `chat/` | Support chat widget backend |
| `data/` | Scheduled jobs — campaign sends, CAPI relays |
| `a/` | Self-hosted analytics collector, geo lookups |
| `PHPMailer/` | Vendored mail library |

## Tech stack

- **Runtime:** PHP 7.4+ behind nginx/Apache, no build step
- **Storage:** SQLite, auto-created on first request (`CREATE TABLE IF NOT EXISTS` per module)
- **Config:** environment variables, see [`.env.example`](.env.example)

## Getting started

```bash
cp .env.example .env     # fill in the keys you need; empty keys just disable that feature
php -S 127.0.0.1:8000    # or point php-fpm/nginx at this folder
```

The SQLite databases under `data/` are created automatically on first request — there's
no schema to import. See [`.env.example`](.env.example) for the full list of environment
variables (ad platform tokens, SMTP/Resend key, hCaptcha secret, Telegram bot tokens, etc.)
and `ENGINE.md` for which subsystems need which keys.

## Not included

This export is **backend code only** — it excludes everything that isn't the engine itself:

- Landing pages, marketing/legal pages, and their media, fonts, and i18n copy
- Member dashboard UI assets (images, video, CSS/JS) — only the PHP logic behind it ships here
- `.env` files and any hardcoded API keys/tokens (set via environment variables on the
  server — see `getenv(...)` calls throughout the code for the expected names)
- SQLite databases (`*.sqlite`, `*.db`) — user accounts, tracker events, reviews, etc.
- `uploads/`, `chat/sessions/`, `chat/resumes/`, OAuth state files — user-submitted content
- Historical `.bak` / timestamped backup copies left on the server from manual edits
- Hosting-panel symlinks pointing at absolute server paths, scratch test endpoints,
  and internal planning notes — stripped from the import

## License

Proprietary — all rights reserved. See [`LICENSE`](LICENSE).
