# Mobile Shop Inventory + POS + Mobile Service — Claude Code Instructions

## Objective
Build a production-ready web application for a mobile phone/accessories shop that sells products and provides mobile repair/service.

## Preferred stack
- Backend: Laravel 12, PHP 8.2+
- Database: MySQL 8+
- Auth/API: Laravel Sanctum
- Frontend: React + Inertia.js + TypeScript
- UI: Tailwind CSS
- Authorization: Spatie Laravel Permission
- Barcode: Code128/QR-capable library as appropriate
- Testing: PHPUnit/Pest + Laravel feature tests
- Use migrations, factories, seeders, form requests, policies, services/actions, resources, and domain-oriented application services.

## Critical business rules
1. Never use a manually edited product stock number as the sole source of truth.
2. Every stock change must create a stock movement.
3. Sale decreases stock; sale return increases stock.
4. Purchase increases stock; purchase return decreases stock.
5. A purchase may be CASH, DUE, or PARTIAL.
6. A party can have an advance balance/credit with the shop.
7. Product sale and service charge may exist on the SAME invoice.
8. Service parts used must affect inventory.
9. Service revenue and product-sale revenue must be reportable separately.
10. A cancelled/void transaction must be handled explicitly; do not silently delete financial records.
11. Money must use decimal-safe database columns; never use floating point for monetary values.
12. Store transaction date/time and the user who created/updated the transaction.
13. Use database transactions for stock + payment + ledger operations that must succeed/fail together.
14. Do not duplicate business logic between controllers.
15. Prefer service/action classes for transaction workflows.
16. Use soft deletes only where appropriate; financial/stock history should remain auditable.

## Development protocol
Before coding a task:
1. Read only CLAUDE.md, the relevant module file under docs/modules/, and the corresponding task in docs/tasks/.
2. Inspect existing implementation before changing it.
3. Implement only the requested task and its direct dependencies.
4. Add/update tests for the task.
5. Run relevant tests and static checks.
6. Do not refactor unrelated modules.
7. Do not introduce new packages unless necessary and documented.
8. Update docs/tasks/TASK_STATUS.md after completing a task.

## Token-efficiency rule
Do NOT load every specification file for every task.
Use the task map below to select only the required documents.

## Task map
- Foundation/auth: docs/modules/01-foundation-auth.md
- Product/category/stock: docs/modules/02-products-inventory.md
- Purchase/party ledger: docs/modules/03-purchase-party-ledger.md
- POS/sales: docs/modules/04-pos-sales.md
- Returns: docs/modules/05-returns.md
- Mobile repair/service: docs/modules/06-mobile-service.md
- Expenses: docs/modules/07-expenses.md
- Barcode/printing: docs/modules/08-barcode-printing.md
- Reports/dashboard: docs/modules/09-reports.md
- Testing/QA: docs/modules/10-testing.md

For cross-module work, read only the listed modules for that task.

## Implementation order
Follow docs/tasks/TASK_INDEX.md sequentially unless a dependency requires otherwise.

## Definition of Done
A task is complete only when:
- requested schema/API/UI behavior exists,
- validation and authorization are implemented,
- relevant tests pass,
- no obvious N+1 query is introduced,
- stock/ledger invariants remain valid,
- task status is updated.

## Git requirements

Use Conventional Commits.

Allowed prefixes:
- feat
- fix
- refactor
- test
- docs
- chore
- perf
- style

Commit messages should be concise and describe the completed change.

Example:
feat: implement product inventory management

Do not create meaningless commits such as:
- update
- changes
- final
- work
- test

## Docker requirements

The application must be containerized for easy deployment.

Required:
- PHP/Laravel application container
- Nginx container
- MySQL container
- Docker Compose
- .env.example
- .dockerignore
- production Docker configuration
- persistent MySQL volume
- persistent Laravel storage where required

The Docker setup must support:

Development:
docker compose up -d

Production:
docker compose -f docker-compose.prod.yml up -d

The application must document:
- initial setup
- environment variables
- database migration
- database seeding
- storage link
- frontend build
- queue worker
- scheduler
- backups
- logs
- restart procedures