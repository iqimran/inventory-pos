# Module 02 — Products & Inventory

## Master data
- categories
- subcategories
- brands (optional but recommended)
- units
- products

## Product fields
- id
- category_id
- subcategory_id
- brand_id nullable
- unit_id
- name
- sku unique
- barcode unique nullable/required according to business policy
- purchase_price
- retail_price
- wholesale_price
- reorder_level
- active
- timestamps

## Stock
Do not make stock edits directly from product CRUD.

Use:
stock_movements
- id
- product_id
- type
- quantity
- unit_cost nullable
- reference_type nullable
- reference_id nullable
- occurred_at
- created_by
- notes

Quantity direction can be represented either by signed quantity or type + positive quantity. Pick one convention and use it consistently. Signed quantity is recommended.

## Search
Support:
- barcode exact search,
- SKU exact search,
- name partial search,
- category/subcategory filters,
- active filter.

Barcode lookup should be indexed and fast.

## Stock adjustment
Admin-only unless permission is granted.
Every adjustment creates a stock movement with reason.

## Acceptance criteria
- Product CRUD works.
- Category/subcategory hierarchy works.
- Barcode is unique.
- Product search works by barcode/name/category.
- Stock is derived consistently from movements.
- Stock cannot become negative unless an explicit configurable policy permits it.
