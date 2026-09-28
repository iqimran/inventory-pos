# Deployment & Operations Guide

Mobile Shop Inventory + POS + Mobile Service — Laravel 12, React/Inertia, MySQL 8.

Every command in this guide was run against the Docker setup in this repository (see
`docs/deployment-verification.md`).

## 1. Architecture

```
                 ┌──────────── Docker network "backend" (internal) ────────────┐
 Browser ─HTTPS─▶ TLS proxy ─HTTP─▶ web (Nginx) ─FastCGI:9000─▶ app (PHP-FPM) ──▶ mysql (MySQL 8.4)
                 │                    │ public/, /build                │           volume: mysql-data
                 │                    └── reads app-storage (ro)       │
                 │                                   scheduler (schedule:work)
                 │                                   queue (optional, --profile queue)
                 └─ app, scheduler, queue share volume: app-storage (uploads, logs, sessions) ┘
```

- **app** — PHP 8.3-FPM with the code, `vendor/` and built frontend assets baked into the image.
- **web** — Nginx with only `public/`; proxies PHP to `app:9000`. The only published port.
- **mysql** — MySQL 8.4 (MariaDB is not supported). Never published outside the Docker network.
- **scheduler** — runs `php artisan schedule:work`: nightly, report-only stock and ledger integrity checks.
- **queue** — optional worker. No feature queues work today; enable it only when one does.

Persistent data lives in two named volumes: **`mysql-data`** (the database) and **`app-storage`**
(`storage/`: organization logo, logs, sessions, framework cache). Everything else is rebuilt from the image.

## 2. Requirements

| | Development | Production (single VPS) |
|---|---|---|
| Docker Engine + Compose v2 | yes | yes |
| CPU / RAM | any | 2 vCPU / 2 GB RAM minimum (4 GB recommended) |
| Disk | 5 GB | 20 GB + backups |
| Open ports | 8080, 5173 (localhost) | 80/443 via your TLS proxy; 22 for SSH. **Not** 3306. |

Without Docker: PHP 8.2+ (bcmath, pdo_mysql, intl, mbstring, fileinfo), Composer, Node 20+, MySQL 8+
(see the README).

## 3. Configuration (`.env`)

`.env.example` documents every variable. The ones that matter in production:

| Variable | Production value | Notes |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Enforced by `docker-compose.prod.yml` as well. |
| `APP_KEY` | generated once | `docker compose … run --rm app php artisan key:generate --show`. Keep it secret; never change it (sessions and signed links depend on it). |
| `APP_URL` | `https://your-domain` | |
| `TRUSTED_PROXIES` | IP of your TLS proxy, or `*` | Required behind Caddy/Nginx/a load balancer so HTTPS and client IPs are correct. Use `*` only when the web port is reachable **only** through the proxy. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | strong, unique | The MySQL container creates this database and user on first start. `DB_USERNAME` must not be `root`. |
| `DB_ROOT_PASSWORD` | strong, unique | MySQL root, used by backups/maintenance. |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | your admin | Used once by `db:seed`. Required in production. |
| `REPORT_TIMEZONE` | e.g. `Asia/Dhaka` | Reports group days/months in local time. Keep `APP_TIMEZONE=UTC`. |
| `HTTP_PORT` | `80`, or `127.0.0.1:8080` behind a host proxy | Host port of the web container. |
| `APP_VERSION` | release tag, e.g. `1.0.0` | Image tag; makes rollback a tag change. |
| `RUN_MIGRATIONS` | `false` (set `true` only for a deploy) | The app container migrates on start when `true`. |
| `SESSION_SECURE_COOKIE` | `true` behind HTTPS | |
| `MAIL_*` | SMTP settings | Only needed for password-reset e-mails. |

**Never commit `.env`** (git-ignored). Keep it on the server with `chmod 600 .env`.

## 4. Local development with Docker

```bash
cp .env.example .env                 # once; database settings come from docker-compose.yml
export UID=$(id -u) GID=$(id -g)     # files written by the container stay yours
docker compose build                 # first time ~5 minutes, cached afterwards
docker compose up -d                 # app http://localhost:8080 · Vite HMR localhost:5173

docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed    # roles, permissions, admin (local: admin@example.com / "password"), units, expense types
```

| Task | Command |
|---|---|
| Stop / start | `docker compose stop` · `docker compose start` |
| Restart one service | `docker compose restart app` |
| Logs | `docker compose logs -f app` (also `nginx`, `mysql`, `node`) |
| Shell | `docker compose exec app sh` |
| Artisan / Composer | `docker compose exec app php artisan …` · `docker compose exec app composer …` |
| Tests | `docker compose exec app php artisan test` (always in-memory SQLite) |
| Critical tests only | `docker compose exec app php artisan test --group=critical` |
| Frontend | served live by the `node` service (Vite). One-off build: `docker compose exec node npm run build` |
| Storage link | `docker compose exec app php artisan storage:link` (optional: uploads are served through the app) |
| MySQL client | `127.0.0.1:33306` (user `pos` / `secret`), or `docker compose exec mysql mysql -upos -psecret mobile_shop_pos` |
| Remove everything incl. data | `docker compose down -v` |

The database lives in the `mysql-data` volume and survives `docker compose down`/`up`; only `down -v`
deletes it. Tests can never touch it: `phpunit.xml` forces in-memory SQLite and the test base class refuses
any database that is not SQLite or named `*_testing` (MySQL runs: `php artisan test -c phpunit.mysql.xml`).

## 5. Production deployment (single VPS)

### 5.1 First deployment

```bash
# 1. Server: install Docker Engine + Compose plugin, create a deploy user, open 80/443 only.
git clone https://github.com/iqimran/inventory-pos.git /srv/pos && cd /srv/pos
git checkout v1.0.0                              # deploy a release tag, never an arbitrary commit

# 2. Configuration
cp .env.example .env && chmod 600 .env           # edit: see section 3
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show
#   → copy the printed key into APP_KEY in .env

# 3. Build and start (migrations run once because RUN_MIGRATIONS=true)
export APP_VERSION=1.0.0
docker compose -f docker-compose.prod.yml build
RUN_MIGRATIONS=true docker compose -f docker-compose.prod.yml up -d

# 4. Seed roles, permissions, admin, units and expense types (first deployment only)
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force

# 5. Check
docker compose -f docker-compose.prod.yml ps                       # all healthy
docker compose -f docker-compose.prod.yml exec app php artisan app:check-environment
curl -fsS http://127.0.0.1/up                                      # 200
```

Tip: `alias dcp='docker compose -f docker-compose.prod.yml'` shortens every command below.

### 5.2 HTTPS

Terminate TLS in front of the web container and set `TRUSTED_PROXIES`. Simplest: Caddy on the host with
`HTTP_PORT=127.0.0.1:8080` in `.env` (web container only reachable locally) and `TRUSTED_PROXIES=*`:

```caddyfile
pos.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy obtains and renews certificates automatically. Set `APP_URL=https://pos.example.com` and
`SESSION_SECURE_COOKIE=true`, then `docker compose -f docker-compose.prod.yml up -d` to apply.

### 5.3 Deploying a new release

```bash
cd /srv/pos
docker/scripts/backup.sh                          # always back up first
git fetch --tags && git checkout v1.1.0
export APP_VERSION=1.1.0
docker compose -f docker-compose.prod.yml build
RUN_MIGRATIONS=true docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force   # registers new permissions; keeps role customisations
docker compose -f docker-compose.prod.yml ps && curl -fsS http://127.0.0.1/up
```

Then set `RUN_MIGRATIONS=false` again (or keep passing it only on deploy commands).

### 5.4 Migrations, caches, storage link

- **Migrations** run on container start with `RUN_MIGRATIONS=true`, or manually:
  `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force`.
- **Config/route/event/view caches** are rebuilt automatically every time the app, scheduler and queue
  containers start (`php artisan optimize`). After editing `.env`: `docker compose -f docker-compose.prod.yml up -d`
  (recreates containers with the new values). Never run `config:cache` on the host.
- **Storage link** — the image already contains `public/storage → storage/app/public`, and the web
  container mounts the storage volume read-only; no action needed. (Locally: `php artisan storage:link`.)
  The organization logo is stored privately and served by the application itself.

### 5.5 Scheduler and queue

- The **scheduler** container runs `schedule:work`. Scheduled: `inventory:reconcile` and `ledger:reconcile`
  at 02:00 (report only). A mismatch is logged as an error and written to `storage/logs/scheduler.log`.
  List: `docker compose -f docker-compose.prod.yml exec scheduler php artisan schedule:list`.
- The **queue** worker is optional: `docker compose -f docker-compose.prod.yml --profile queue up -d queue`.
  Stop it with the same `--profile queue`. Include `--profile queue` in `down` commands if you started it.

### 5.6 Logs

```bash
docker compose -f docker-compose.prod.yml logs -f app          # Laravel (stderr) + PHP-FPM
docker compose -f docker-compose.prod.yml logs -f web          # Nginx access/errors
docker compose -f docker-compose.prod.yml logs --since 1h mysql
docker compose -f docker-compose.prod.yml exec app tail -n 100 storage/logs/scheduler.log
```

Application sign-ins, permission changes, price overrides and adjustments are also visible in the app under
**Administration → Audit log**. Limit Docker log size in `/etc/docker/daemon.json`:
`{"log-driver": "json-file", "log-opts": {"max-size": "20m", "max-file": "5"}}`.

### 5.7 Restart

| Situation | Command |
|---|---|
| Restart a service | `docker compose -f docker-compose.prod.yml restart app` |
| Apply `.env` changes | `docker compose -f docker-compose.prod.yml up -d` |
| Stop / start everything | `docker compose -f docker-compose.prod.yml stop` · `… start` |
| Server reboot | automatic (`restart: unless-stopped`); ensure Docker is enabled: `systemctl enable docker` |

A crashed process is restarted by Docker automatically (verified by killing PHP-FPM).

## 6. Backups and restore

`docker/scripts/backup.sh` writes `backups/<timestamp>-database.sql.gz` and
`backups/<timestamp>-storage.tar.gz`, refuses to keep an empty or incomplete dump, and deletes backups
older than `RETENTION_DAYS` (default 14).

```bash
docker/scripts/backup.sh                                   # production stack
crontab -e                                                 # nightly at 01:30, before the 02:00 checks:
30 1 * * * cd /srv/pos && docker/scripts/backup.sh >> /var/log/pos-backup.log 2>&1
```

**Copy backups off the server** (another machine, S3/B2 with `rclone`, …). A backup on the same disk
does not survive losing the server. `backups/` is git-ignored; dumps contain customer and financial data.

**Restore** (replaces the current database; take a fresh backup first):

```bash
docker/scripts/restore.sh backups/20260929-013000-database.sql.gz backups/20260929-013000-storage.tar.gz
```

The script verifies the dump, asks for confirmation, restores, and runs the stock and ledger
reconciliation checks. **Practise a restore** on a staging copy periodically.

## 7. Rollback

1. **Application only** (the release added no migrations): point `APP_VERSION` at the previous tag and
   restart. If the previous images are still present: `APP_VERSION=1.0.0 docker compose -f docker-compose.prod.yml up -d --no-build`.
   Otherwise `git checkout v1.0.0 && docker compose -f docker-compose.prod.yml build` first.
2. **Release included migrations**: migrations are forward-only. Restore the backup taken just before
   the deploy (section 6), then start the previous version as in step 1. Transactions entered after that
   backup must be re-entered.
3. **Keep images**: don't `docker image prune -a` right after a deploy; the previous tag is your fastest
   rollback.

## 8. Operational checklist

Before go-live:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`, `APP_URL` with https.
- [ ] Strong unique `DB_PASSWORD` and `DB_ROOT_PASSWORD`; `DB_USERNAME` is not root.
- [ ] TLS proxy in front, `TRUSTED_PROXIES` set, `SESSION_SECURE_COOKIE=true`.
- [ ] Firewall: only 22, 80, 443 open. `docker port` shows nothing for mysql.
- [ ] Admin signs in; change the seeded admin password; create staff users with least privilege.
- [ ] Settings → Organization filled in (name, address, contact number, logo).
- [ ] `REPORT_TIMEZONE` set.
- [ ] Nightly backup cron installed, off-site copy configured, one restore rehearsed.
- [ ] `php artisan test --group=critical` green for the release being deployed.

After each deploy: all containers healthy, `/up` returns 200, sign in, open POS and one report, check
`docker compose -f docker-compose.prod.yml logs --since 10m app` for errors.

## 9. Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page, assets load from `:5173` | A stale `public/hot` from the Vite dev server: `rm public/hot`. |
| `Refusing to run tests against the non-test database` | Working as intended: tests only run on SQLite or a `*_testing` database. |
| Redirects to `http://` behind HTTPS | Set `TRUSTED_PROXIES` and `APP_URL=https://…`, then `up -d`. |
| New menu items/permissions missing after an upgrade | `… exec app php artisan db:seed --class=RolesAndPermissionsSeeder --force` |
| `docker compose down` leaves a volume "in use" | The optional queue container still exists: add `--profile queue`. |
| Port 33060 already in use locally | That is MySQL's X-protocol port; the dev stack uses 33306 (`FORWARD_DB_PORT`). |
| Stock or ledger mismatch in `scheduler.log` | Investigate before fixing: `php artisan inventory:reconcile` / `ledger:reconcile` list the rows; `--fix` rewrites caches from the ledgers. |
