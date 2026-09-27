# Business Requirements

## 1. Business
The application supports a mobile shop that:
- sells mobile phones/accessories/parts,
- purchases stock from parties/suppliers,
- manages party balances and advance payments,
- provides mobile repair/service,
- charges both product/parts and service fees,
- prints customer sale slips/invoices,
- prints product barcode labels,
- tracks expenses,
- provides daily/monthly sales and service revenue reports.

## 2. Actors
### Admin
Full system control:
- users/roles/permissions,
- master data,
- products and stock,
- purchases,
- parties and payments,
- sales and returns,
- mobile service jobs,
- expenses,
- reports,
- configuration.

### General User
Basic operational features according to assigned permissions:
- product search,
- POS sale,
- customer receipt,
- purchase entry if permitted,
- service job operations if permitted,
- stock lookup,
- selected reports.

Authorization must be permission-based even though the initial roles are Admin and General User.

## 3. Product requirements
- Category and subcategory.
- Brand optional.
- Unit.
- SKU/internal product code.
- Barcode.
- Product name.
- Purchase price.
- Retail sale price.
- Wholesale sale price.
- Reorder level.
- Active/inactive.
- Current stock derived from stock movements or a maintained cached balance.
- Search by name, SKU, barcode, category, subcategory.
- Barcode label printing.

## 4. Purchase requirements
A purchase contains:
- supplier/party,
- invoice/reference number,
- purchase date,
- line items,
- quantity,
- purchase price,
- discount,
- tax if enabled later,
- subtotal/total,
- paid amount,
- due amount,
- payment method,
- notes.

Purchase payment status:
- PAID/CASH
- PARTIAL
- DUE

Party may receive advance payment even when there is no purchase attached.

## 5. Party requirements
A party can be a supplier, customer, or both.
- Opening balance.
- Purchases.
- Purchase payments.
- Sales.
- Customer payments.
- Advance/credit.
- Ledger entries.
- Balance statement.

Use signed ledger semantics rather than scattered due columns.

## 6. Sales/POS requirements
Sale types:
- Retail
- Wholesale

Sale can contain:
- product lines,
- discounts,
- customer/party,
- paid amount,
- due amount,
- payment method,
- notes.

A sale produces a customer voucher/slip.

## 7. Sales return
- Return against an original sale where possible.
- Select returned items and quantities.
- Returned quantity cannot exceed eligible sold quantity.
- Returned stock goes back into inventory.
- Financial adjustment is recorded.
- Customer refund/credit adjustment is recorded.
- Return cannot silently modify historical sale totals.

## 8. Mobile service
A service job represents a customer's damaged phone.

Minimum data:
- customer,
- phone/device information,
- problem/complaint,
- diagnosis,
- estimated charge,
- technician,
- status,
- parts used,
- service charge,
- notes,
- dates.

Service status:
RECEIVED → DIAGNOSING → WAITING_FOR_APPROVAL → IN_PROGRESS → READY → DELIVERED
with CANCELLED where applicable.

A service invoice can contain:
- parts/products,
- service/labor charge lines.

Example:
IC/part = 800
Service charge = 500
Invoice total = 1,300

The part creates a product-sale/stock movement effect while the service charge is service revenue.

## 9. Expense management
- Expense type master.
- Expense entry.
- Amount.
- Date.
- Payment method.
- Reference.
- Notes.
- User/audit information.

## 10. Reports
Required:
- daily sales quantity and amount,
- monthly sales quantity and amount,
- daily product-sale revenue,
- monthly product-sale revenue,
- daily mobile-service revenue,
- monthly mobile-service revenue,
- purchase summary,
- stock summary,
- party outstanding/advance,
- expenses,
- combined revenue.

Revenue reports must distinguish:
1. product/parts revenue,
2. service/labor revenue,
3. combined gross sales/revenue.

## 11. Non-functional requirements
- Responsive POS UI.
- Fast barcode/product search.
- Transaction-safe stock updates.
- Auditable financial records.
- Permission-controlled operations.
- Pagination for large lists.
- Server-side validation.
- Database indexes on barcode, SKU, product name/search fields, dates, foreign keys.
- Test critical transaction workflows.
