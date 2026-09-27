# Module 09 — Dashboard & Reports

## Dashboard
Date-range filter.

Show:
- product sales amount,
- product sales quantity,
- mobile service revenue,
- combined revenue,
- purchases,
- expenses,
- outstanding receivables,
- outstanding supplier payables,
- low-stock products.

## Required reports

### Daily sales
Columns:
date/time, invoice, sale type, customer, quantity, subtotal, discount, total, paid, due.

### Monthly sales
Aggregate by day/month:
quantity and amount.

### Product revenue
Revenue generated from PRODUCT lines.

### Mobile service revenue
Revenue generated from SERVICE lines.

### Combined revenue
PRODUCT revenue + SERVICE revenue.

### Stock
- current stock,
- low stock,
- stock movement history.

### Party ledger
- opening balance,
- transactions,
- payments,
- returns,
- closing balance.

### Expense
- expense by type,
- daily/monthly total.

## Reporting rule
A service invoice containing parts + service charge must allow:
- parts/product amount to appear in product revenue,
- service charge to appear in service revenue,
- total invoice amount to appear in combined revenue.

Do not classify the entire invoice as service revenue.

## Performance
Use indexed date columns and aggregate queries.
Paginate detailed reports.
