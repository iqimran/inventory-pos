# Architecture

## Recommended architecture
Laravel 12 modular monolith with React + Inertia + TypeScript.

Keep domain modules separated in application code while sharing core infrastructure.

Suggested structure:

app/
  Actions/
  Domain/
    Inventory/
    Sales/
    Purchasing/
    PartyLedger/
    MobileService/
    Expense/
    Reporting/
  Http/
    Controllers/
    Requests/
    Resources/
  Models/
  Policies/
  Services/

resources/js/
  pages/
  components/
  layouts/
  features/
    products/
    inventory/
    purchases/
    sales/
    service/
    parties/
    expenses/
    reports/

## Transaction architecture
A business transaction should generally follow:

Controller
→ Form Request validation
→ Action/Service
→ DB transaction
→ Models + ledger/movement records
→ event/audit if required
→ response

Do not put complex stock/ledger mutation logic in controllers.

## Core concepts

### Product
Master definition of an item.

### Stock Movement
Immutable-ish operational history:
- movement_type,
- product,
- quantity,
- reference type/id,
- unit cost where applicable,
- occurred_at,
- created_by.

Movement examples:
PURCHASE_IN
PURCHASE_RETURN_OUT
SALE_OUT
SALE_RETURN_IN
SERVICE_PART_OUT
ADJUSTMENT_IN
ADJUSTMENT_OUT

### Party Ledger
Financial history for suppliers/customers:
- debit/credit direction,
- amount,
- reference transaction,
- date,
- notes,
- created_by.

Do not calculate balances from only one `due` field.

### Sale
Commercial transaction.

### Service Job
Repair workflow.

### Invoice/Sale document
Can contain different line types:
PRODUCT
SERVICE

A single invoice may contain both.

## Money
Use DECIMAL(15,2) or suitable precision.
Never PHP/database FLOAT for money.

## IDs
Use bigint IDs unless there is a strong reason for UUID/ULID.
Public references/invoice numbers can be human-friendly unique numbers.

## Invoice numbering
Examples:
SALE-202609-000001
PUR-202609-000001
SRV-202609-000001

Number generation must be concurrency-safe.

## Auditability
For financial and stock operations preserve:
- created_by,
- updated_by where relevant,
- timestamps,
- source/reference,
- status.

Avoid hard deletion of completed sales, purchases, payments, returns, and expenses.

## Reporting strategy
Start with normalized transactional tables and indexed queries.
Do not introduce a reporting warehouse prematurely.
If volume becomes high, add summary tables/materialized reporting later.
