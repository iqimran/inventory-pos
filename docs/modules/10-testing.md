# Module 10 — Testing & QA

## Highest-priority tests

### Inventory invariants
- Purchase increases stock.
- Sale decreases stock.
- Sale return increases stock.
- Purchase return decreases stock.
- Service part consumption decreases stock.
- Stock adjustment changes stock only with permission.

### Purchase/ledger
- Cash purchase → zero due.
- Partial purchase → remaining payable.
- Due purchase → full payable.
- Supplier advance exists without purchase.
- Advance can be reconciled.

### POS
- Retail pricing.
- Wholesale pricing.
- Product search/barcode.
- Sale payment.
- Customer due.
- Receipt.

### Returns
- Cannot exceed eligible quantity.
- Ledger adjustment correct.
- Stock adjustment correct.

### Service
- Service job lifecycle.
- Parts consumption.
- Service charge.
- Combined invoice.
- Separate product/service revenue.

### Authorization
- Admin full access.
- General user only permitted actions.
- Restricted stock adjustments.
- Restricted financial adjustments.

## Suggested test levels
1. Unit tests for calculations.
2. Feature tests for workflows.
3. Database transaction tests.
4. Authorization tests.
5. Critical UI/E2E tests for POS and service checkout.

## Financial calculation examples
Subtotal = sum(line totals)
Total = subtotal - discount + applicable tax
Due = total - paid - applicable credit/advance

Never allow negative due unless the transaction explicitly represents a credit/refund/advance.
