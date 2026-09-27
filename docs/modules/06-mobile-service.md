# Module 06 — Mobile Repair / Service

## Customer
Reuse Party where appropriate, or use a customer profile linked to Party.

## Device
Recommended fields:
- customer_id
- brand
- model
- IMEI1 nullable
- IMEI2 nullable
- serial_no nullable
- color nullable
- notes

## Service job
Fields:
- job_no
- customer_id
- device_id
- technician_id nullable
- complaint
- diagnosis
- estimated_amount
- approved_amount nullable
- service_charge
- status
- received_at
- promised_at nullable
- delivered_at nullable
- notes
- created_by

## Service parts
A service job can consume products from inventory:
service_job_items
- service_job_id
- product_id
- quantity
- unit_price
- cost_snapshot
- line_total

When finalizing/consuming parts, create SERVICE_PART_OUT stock movements.

## Service charge
Service charge is NOT a product stock movement.
It is a SERVICE revenue line.

## Combined invoice
One service invoice can contain:

PRODUCT:
- IC = 800
- Connector = 200

SERVICE:
- Repair/labor = 500

Total = 1,500

The invoice should expose line_type = PRODUCT or SERVICE.

Product lines affect inventory.
Service lines do not.

## Workflow
RECEIVED
→ DIAGNOSING
→ WAITING_FOR_APPROVAL
→ IN_PROGRESS
→ READY
→ DELIVERED

CANCELLED may be used when the job is abandoned.

## Important business rule
Do not deduct parts merely because a technician typed them into a draft.
Deduct inventory when the business event says the parts are actually consumed/charged.

## Acceptance criteria
- Service job can be opened for a customer/device.
- Technician can diagnose and update status.
- Parts can be added.
- Service charge can be added.
- Final bill combines parts + service charge.
- Parts decrease stock.
- Service revenue is separately reportable.
- Customer receives a service invoice/slip.
