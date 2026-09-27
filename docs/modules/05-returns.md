# Module 05 — Returns

## Product sale return
Return should reference the original sale.

Fields:
- return_no
- original_sale_id
- customer_id nullable
- return_date
- subtotal
- refund_amount
- adjustment_amount
- reason
- status
- created_by

Items:
- original_sale_item_id
- product_id
- quantity
- unit_price
- amount

## Rules
- Cannot return more than eligible sold quantity.
- Returned quantity increases stock.
- Financial adjustment must be recorded.
- If original sale had due, return may reduce receivable.
- If already paid, return may create refund/credit according to selected policy.
- Do not rewrite original sale history.

## Purchase return
Purchase return decreases stock and reduces supplier payable/creates supplier credit.

## Acceptance criteria
- Stock is correct after return.
- Duplicate/excess return is blocked.
- Ledger is adjusted correctly.
- Original transaction remains auditable.
