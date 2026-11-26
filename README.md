# OpenMVP system snapshot

Snapshot of the OpenMVP portal, tracker admin and operational scripts (2026-11-26).

- `portal/` — static site served from `/var/www/openmvp-portal/` on 5.144.179.170 (`openmvp.online`).
- `icrm/` — live tracker admin served from `/var/www/wifepornhub.ru/icrm/` (`/icrm/` on openmvp.online).
- `system-local/` — local source copy of the tracker from `/home/main/system`.
- `scripts/wintask/` — Telethon watcher that parses agent footers and feeds `agent_runs`/`llm_calls`.
- `scripts/portal/` — shared top-nav CSS and page generator.

## Deploy notes

1. No secrets are included: DB passwords, admin keys, OpenRouter/TG tokens, Telegram sessions and logs are intentionally omitted or set to `CHANGE_ME`.
2. Set DB credentials on the target host before running; schema: `icrm/schema.sql`.
3. Money-center lives in `icrm/money/` with its own MariaDB database (`money`); do not reuse the tracker DB password.
4. Portal version bumps are made in `portal/index.html` (`index-live.js?v=N`); `chown www-data` after deploy.
5. After editing the watcher, restart `wintask-watch.service`; it needs a valid Telegram user session that is NOT committed here.

This repository is a code snapshot, not an auto-deploy pipeline.
