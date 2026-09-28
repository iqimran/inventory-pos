# Mobile Shop Inventory + POS + Mobile Service

Start with `CLAUDE.md`.

Then execute tasks in `docs/tasks/TASK_INDEX.md` one at a time.

The design intentionally separates:
- product revenue,
- mobile service revenue,
- stock movements,
- party ledger,
- expenses.

This is important because one mobile repair invoice can be individual or contain both (parts or accessories) and service charges.

Example:
IC/Part: 800
Service charge: 500
Invoice total: 1,300

The 800 product line affects stock and product revenue.
The 500 service line affects service revenue but not stock.
The 1,300 contributes to combined revenue.

The task files are deliberately small so Claude Code can load only the context needed for the current task.

## Local development

Requirements: PHP 8.2+, Composer, Node 20+, MySQL 8+.

```bash
composer install
npm install
cp .env.example .env            # then set DB_* and ADMIN_* values
php artisan key:generate
mysql -u root -p -e "CREATE DATABASE mobile_shop_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan app:check-environment   # validates config + MySQL 8 connectivity
php artisan migrate --seed          # creates roles, permissions and the initial Admin
composer dev                        # serves app, queue, logs and Vite
```

The seeder creates the Admin from `ADMIN_NAME` / `ADMIN_EMAIL` / `ADMIN_PASSWORD`. In local
environments an empty `ADMIN_PASSWORD` falls back to `password`; elsewhere the admin is skipped
until a password is provided. Public self-registration is disabled — an Admin creates staff accounts.

### Checks

```bash
php artisan test        # PHPUnit feature tests (in-memory SQLite)
vendor/bin/pint --test  # PHP code style
npm run lint && npx tsc --noEmit && npm run format:check
```

To run the suite against MySQL, create `mobile_shop_pos_testing` and run
`DB_CONNECTION=mysql DB_DATABASE=mobile_shop_pos_testing php artisan test`.

### Conventions

- `app/Actions` — transaction workflows (one class per use case), called from thin controllers.
- `app/Domain/<Module>` — domain services per business module (see `docs/02-architecture.md`).
- `app/Enums/Permission.php` — the single catalogue of permission names; `SystemRole` holds the built-in roles.
- Audit columns: use `$table->userstamps()` in migrations and the `HasUserstamps` model trait.
- Frontend: pages in `resources/js/pages`, module UI in `resources/js/features/<module>`,
  sidebar entries in `resources/js/config/navigation.ts` (each with its required `permission`).

### Inventory rules

- Stock is never edited directly. `App\Domain\Inventory\StockService` is the only writer: every change
  appends an immutable `stock_movements` row (signed quantity: `+` in, `−` out; the sign comes from the
  movement type) and updates the `product_stocks` running balance in the same row-locked transaction.
- Mistakes are corrected with a compensating movement (e.g. a reverse adjustment), never by editing history.
- Stock cannot go negative unless `INVENTORY_ALLOW_NEGATIVE_STOCK=true`.
- `php artisan inventory:reconcile` verifies every balance against `SUM(stock_movements.quantity)`;
  `--fix` rewrites mismatches from the ledger.
- New products start at zero; record opening stock with a stock adjustment (reason *Opening stock*).

### Party ledger & purchasing rules

- Party balances come from the immutable `party_ledger_entries` table, written only by
  `App\Domain\PartyLedger\PartyLedgerService`. Debit = the party owes the shop more; credit = the shop
  owes the party more. Balance > 0 is receivable / supplier advance, balance < 0 is payable.
- A purchase is recorded in one transaction: items, stock-in movements, the payable (credit), any
  advance consumed, and the payment made at purchase time (debit). PAID / PARTIAL / DUE is derived.
- Supplier advances are payments not yet allocated to a purchase (`payment_allocations`); later
  purchases consume them oldest first. Payments can target one purchase or settle dues oldest first.
- Purchases, payments and returns are never deleted or edited. Correct mistakes with a purchase return
  or a permission-gated manual ledger adjustment.
- Workflows lock the party row before stock rows (always in that order) and retry on deadlock.
- `php artisan ledger:reconcile [--fix]` verifies cached party balances and payment allocation totals.
- New permissions (`purchases.return`, `payments.create`, `ledger.adjust`) are added by re-running
  `php artisan db:seed --class=RolesAndPermissionsSeeder` on existing installs.

### POS & sales rules

- A sale is recorded in one transaction: invoice (`SALE-YYYYMM-000001`, row-locked sequence),
  stock-out movements, customer ledger (debit sale total, credit amount paid), and the payment.
- Prices always come from the product (retail or wholesale). Charging another price needs the
  `sales.price_override` permission; line and invoice discounts are capped at the amounts they discount.
- Walk-in sales must be paid in full; a due requires a customer (party of type CUSTOMER or BOTH).
- Cost snapshot: `product_stocks.average_cost` is a moving weighted average maintained by `StockService`
  on costed stock-in. It is stamped onto every stock-out movement and each sale item (`unit_cost`,
  `cost_total`) at sale time, so later purchases or price edits never change historical profit.
- Customer dues are collected against one sale or on account (oldest first) with `sales.collect`.
- Shop details on receipts come from `SHOP_NAME`, `SHOP_ADDRESS`, `SHOP_PHONE`, `SHOP_RECEIPT_FOOTER`.
- POS shortcuts: F2 scan, F4 paid, F8 exact amount, F9 complete sale, Esc close results.

### Sale return rules

- A return references the original sale and its lines. Quantities still returnable are derived from
  earlier return lines (counted under a lock on the sale), so excess and duplicate returns are blocked
  even under concurrent requests. The original sale's lines, prices and totals are never modified;
  only its derived settlement fields (`returned_amount`, `due_amount`, `payment_status`) are recomputed.
- Lines are valued at what the customer actually paid (after line and invoice discounts); returning
  everything that remains takes the exact remaining value.
- Returned goods re-enter stock (`SALE_RETURN_IN`) at the original sale's cost snapshot.
- Money: the ledger is credited with the return value (`SALE_RETURN`). It first reduces what is still
  due on the sale (`adjustment_amount`); the already-paid part is refunded in cash (`CUSTOMER_REFUND`
  payment + ledger debit, capped at what the shop actually owes the customer) and/or kept as store credit
  (`credit_amount`). Walk-in returns are always refunded in full.
- Requires the `returns.create` permission.

### Mobile service rules

- Customers are parties (CUSTOMER / BOTH). Devices belong to one customer and carry brand, model,
  IMEI 1/2 (15 digits with a valid Luhn check digit, unique per customer), serial number and colour.
- Jobs (`JOB-YYYYMM-000001`) follow RECEIVED → DIAGNOSING → WAITING_FOR_APPROVAL → IN_PROGRESS → READY
  → DELIVERED. IN_PROGRESS may go back to WAITING_FOR_APPROVAL (extra fault) and READY back to IN_PROGRESS
  (rework). Asking for approval needs a diagnosis; approval records the approved amount; CANCELLED needs a
  reason and is only possible before invoicing. Every change is logged in `service_job_status_logs`.
  Jobs are never deleted.
- Parts are draft lines while the job is open: adding, changing or removing them does not touch stock.
  Parts are consumed only when a READY job is invoiced — `SERVICE_PART_OUT` movements referencing the job,
  with the cost snapshot stored on the part and the invoice line. After that, parts and charges are locked.
- Service charges are separate SERVICE lines and never create stock movements.
- The service invoice (`SRV-YYYYMM-000001`) combines PRODUCT lines (parts) and SERVICE lines (charges);
  an invoice discount is spread across all lines exactly, and `product_total` / `service_total` hold the
  net revenue per classification (their sum is the invoice total).
- Invoicing is one transaction: invoice, stock-out, customer ledger debit (`SERVICE_INVOICE`), and any
  payment received (credit). Dues are collected per service invoice or on account together with sale
  dues (oldest first) from *Customer payments*, with `sales.collect`.
- Permissions: `service.view` to see jobs, devices and invoices; `service.manage` to open/update jobs,
  change status, edit parts/charges and invoice; part prices other than retail need `sales.price_override`.
  Technicians are active users who hold `service.manage` (or Admin).
