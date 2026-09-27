# Module 03 — Purchase & Party Ledger

## Party
A party may be:
- SUPPLIER
- CUSTOMER
- BOTH

Fields:
- name
- phone
- address
- type
- opening_balance
- opening_balance_type
- active

## Purchase
purchase
purchase_items
payments / party_ledger_entries

Purchase workflow:
1. Select supplier.
2. Add products.
3. Calculate subtotal/discount/total.
4. Enter paid amount.
5. Remaining amount becomes payable.
6. Create purchase stock-in movements.
7. Create corresponding party ledger entry.
8. If paid amount is entered, create payment record.

Use one DB transaction.

## Advance to supplier
A user may pay a supplier before a purchase exists.
This must create a party ledger/payment transaction with an unapplied/advance nature.
Later purchases can consume/reconcile the advance.

## Purchase payment states
PAID: due = 0
PARTIAL: paid > 0 and due > 0
DUE: paid = 0 and total > 0

## Party ledger
Ledger must support:
- purchase payable,
- supplier payment,
- supplier advance,
- customer sale receivable,
- customer payment,
- return adjustment,
- opening balance,
- manual adjustment with permission.

## Acceptance criteria
- Purchase increases stock.
- Cash purchase has no remaining payable.
- Partial purchase creates remaining payable.
- Due purchase creates full payable.
- Advance can exist without a purchase.
- Party statement shows chronological transactions and balance.
- All related operations are atomic.
