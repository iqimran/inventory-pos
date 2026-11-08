# Production Deployment Verification (T056)

Verified on 2026-09-29 at commit `daa150d` (branch `feature/hardening-and-deployment`), on Docker Desktop
28.5 (arm64). Production was exercised with a throwaway `.env` and isolated Compose project, starting from
empty volumes each time; all verification containers, volumes and credentials were deleted afterwards.

**Result: ready for production**, subject to the go-live checklist in `docs/deployment.md` §8 (HTTPS,
secrets, firewall, backups off-site) and creating the `v1.0.0` tag after merge.

## 1. Automated tests

| Check | Result |
|---|---|
| Full suite, SQLite (in-memory) | **475 passed** (7,912 assertions) |
| Full suite, MySQL 8.0 (host, `phpunit.mysql.xml`) | **475 passed** |
| Full suite, MySQL 8.4 (inside the Docker app container) — the production MySQL version | **475 passed** |
| Critical group (`--group=critical`: end-to-end business day, authorization sweep, revenue, returns, purchasing, combined invoice) | **58 passed** (942 assertions) |
| Pint · Prettier · ESLint · TypeScript (`tsc --noEmit`) · `npm run build` | all clean |

The critical end-to-end test drives one business day through the HTTP endpoints and asserts after every
step that each product's stock equals its movement ledger, each party balance equals its ledger, and
`inventory:reconcile` / `ledger:reconcile` pass.

## 2. Business invariants

| Flow | Verified by |
|---|---|
| Purchase → stock IN → supplier ledger → payment/due (cash, partial, due; advance applied) | critical test steps 1–2 |
| Purchase return → stock OUT → supplier credit | critical test step 3 |
| Sale → stock OUT → customer ledger → payment/due (retail, wholesale) | critical test step 5; UI POS sale on the production build (stock 10 → 9) |
| Sale → return → stock IN → ledger adjustment (excess rejected) | critical test step 6 |
| Service job → draft parts (no stock effect) → parts consumed on invoice → service charge → combined invoice | critical test step 8 |
| Revenue: PRODUCT (3,360) + SERVICE (500) = COMBINED (3,860), equal to document totals | critical test step 9; `RevenueReportTest` (IC 800 + connector 200 + repair 500 → 1,000 / 500 / 1,500) |

## 3. Application on the production build

Clean install: 22 migrations on an empty MySQL 8.4 volume, seeders (roles, 33 permissions, admin, units,
expense types), `app:check-environment` valid.

| Check | Result |
|---|---|
| Authentication (sign-in, failed sign-in audited) | pass |
| Admin: 32 module pages (products, inventory, parties, purchases, POS, sales, returns, payments, service jobs/devices/invoices, expenses, barcodes, all reports, users, roles, audit log, settings) | **32/32 load, no browser errors** |
| POS sale through the UI (barcode scan → F8 exact amount → F9 complete → receipt) | `SALE-202609-000001`, 300.00, receipt rendered |
| General User: POS, sales, service jobs, products, due collection | 200 |
| General User: stock adjustment, purchases, reports, users, audit log, settings, expenses | **403** (all 7) |
| Organization logo upload; name shown in sidebar; logo served | pass |
| Printing (receipt, service invoice, labels) | verified in T035–T038 at paper size; receipt rendered on the production build |

## 4. Docker

| Check | Result |
|---|---|
| Development: `docker compose build` / `up -d` | pass; app, nginx, node (Vite HMR), mysql healthy |
| Development: migrations, seeding, sign-in through Nginx with Vite assets | pass |
| Development: MySQL data survives `down`/`up` | pass |
| Production: `docker compose -f docker-compose.prod.yml config` | valid |
| Production images | app 131 MB (www-data, no `.env`/tests/dev dependencies, optimized autoload), web 51 MB |
| Production: containers start from empty volumes; health checks | app, web, mysql healthy; scheduler running |
| Nginx → PHP-FPM over the internal network | pass (`/up`, `/login` 200; hashed assets cached 1 year) |
| MySQL not publicly exposed | `docker port` empty; internal network only |
| MySQL persistence and Laravel storage persistence (database, audit log, uploaded logo) across `down`/`up` | pass |
| Scheduler | nightly `inventory:reconcile` + `ledger:reconcile` listed and passing |
| Queue (optional profile) | starts and stops on demand; not required by any feature |
| Logs | `docker compose logs app/web/mysql`; `storage/logs/scheduler.log` |
| Restart | PHP-FPM killed → restarted by policy (restart count 1), serving again |
| Rollback | switching `APP_VERSION` to another image tag runs that version without rebuilding |
| Backup / restore scripts | 7 rows → deleted → restored to 7, reconciliation passes; with MySQL down the backup exits 1 and keeps no file |

## 5. Security

| Check | Result |
|---|---|
| `.env` never committed (all history); no keys, credentials or dumps tracked | pass |
| `.gitignore` covers `.env.*` (except `.env.example`), keys, dumps, `backups/`, compose overrides | pass |
| Production debug disabled; no stack traces in error pages; no PHP version header | pass |
| `.env`, `.git` and traversal attempts through Nginx | 403 |
| Server-side authorization on every route (sweep of 100+ route/method pairs as a user without permissions) | all 403 except self-service routes |
| Sensitive operations audited (prices, users/roles/permissions, settings, stock and ledger adjustments, price overrides, sign-ins) | pass (`AuditTrailTest`) |
| Database credentials only from the environment; MySQL app user is not root | pass (compose requires them) |
| Tests can never wipe a real database (forced SQLite; guard refuses non-`*_testing` databases) | pass (proven against the real database: refused, data intact) |
| HTTPS behind a proxy only honoured for `TRUSTED_PROXIES`; spoofing from other sources rejected | pass (`TrustedProxyTest`) |

## 6. Performance (one year of data: 55k sales, 110k lines, 110k stock movements, 20k products; MySQL 8)

| Query / page | Result |
|---|---|
| List pages (sales, stock movements, products, service invoices, expenses) | 18–132 ms, ~11–18 KB per page |
| POS product search at 20k products / barcode scan lookup | 21 ms / 2 ms |
| Party statement, 5,000 entries (paginated) | 25 ms (was 221 ms / 1.3 MB unpaginated) |
| Reports for a whole year | 150–370 ms; revenue by product 274 ms (was 551 ms) |
| N+1 queries | prevented outside production (`preventLazyLoading`, asserted by a test) |

Optimizations were made only where measured (statement pagination, single-pass grouped reports).

## 7. Known limitations (not blockers)

- The printed barcode was not scanned with a physical scanner or printed on a physical label printer;
  encoding is covered by tests and layouts were checked at exact paper sizes in Chrome.
- The barcode library is LGPL-3.0-or-later (unmodified Composer dependency); confirm this suits your
  licensing.
- Report day boundaries use a fixed offset per period (no daylight-saving adjustment); fine for
  Asia/Dhaka.
- "All time" report ranges over several years will take seconds; summary tables can be added if needed.
- Supplier drop-downs list all suppliers (fine for hundreds; convert to search for thousands).

## 8. Release

Intended first release tag: **`v1.0.0`**, created manually on `main` after this branch is merged
(see `docs/git-workflow.md`). No tag was created automatically.
