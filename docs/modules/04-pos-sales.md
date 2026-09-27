# Module 04 — POS & Sales

## Sale types
RETAIL
WHOLESALE

## Sale
Fields:
- invoice_no
- customer/party nullable
- sale_type
- sale_date
- subtotal
- discount
- total
- paid
- due
- payment_method
- status
- created_by

## Sale items
- product_id
- quantity
- unit_price
- discount
- line_total
- cost_snapshot if profit reporting is required

Store cost snapshot at sale time so historical gross-profit calculations are not changed by future purchase-price changes.

## Workflow
1. Search/scan product.
2. Add quantity.
3. Select retail/wholesale price or override if permitted.
4. Apply discount.
5. Select customer/party optionally.
6. Receive payment.
7. Calculate due.
8. Create sale.
9. Create stock OUT movements.
10. Create customer receivable ledger entry if due.
11. Print/display sale slip.

## POS UX
Keyboard-friendly barcode scanning.
Fast product lookup.
Cart editing.
Clear subtotal/discount/total/paid/due.
Mobile/tablet/desktop responsive.

## Acceptance criteria
- Retail and wholesale pricing work.
- Sale decreases stock.
- Sale with due creates party receivable.
- Cash sale has zero due.
- Receipt contains invoice number, date, items, quantity, price, discount, total and payment summary.
