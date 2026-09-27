# Module 07 — Expenses

## Expense type
- id
- name
- active

Examples:
- Rent
- Electricity
- Internet
- Salary
- Transport
- Tools/maintenance
- Miscellaneous

## Expense
- expense_no
- expense_type_id
- amount
- expense_date
- payment_method
- reference
- notes
- created_by

## Rules
- Completed expenses should not be hard deleted.
- Edit/delete requires appropriate permission and audit handling.
- Expense amount is included in expense reports.
