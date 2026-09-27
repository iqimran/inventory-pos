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
