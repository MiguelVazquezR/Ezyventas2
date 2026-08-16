# 06 — Customers Module

---

## What It Does
Customer relationship management: CRUD for customer records, balance tracking with credit limits, account statements, balance movements audit trail, and customer payment registration. Supports both individual customers and companies.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/Customer.php` | Customer model with balance, credit, computed fields |
| `app/Models/CustomerBalanceMovement.php` | Audit trail for balance changes |
| `app/Enums/CustomerBalanceMovementType.php` | `credit_usage`, `payment`, `refund_credit`, `cancellation_credit`, `manual_adjustment` |
| `app/Http/Controllers/CustomerController.php` | Customer CRUD + batch operations |
| `app/Http/Controllers/CustomerPaymentController.php` | Customer payment registration |
| `app/Http/Requests/StoreCustomerRequest.php` | Create validation |
| `app/Http/Requests/UpdateCustomerRequest.php` | Update validation |
| `app/Exports/CustomersExport.php` | Customer data export |
| `app/Services/CustomerReportService.php` | Customer analytics/reports |
| `routes/web/customers.php` | Customer + payment routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Customer/Index.vue` | Customer list with search/filter |
| `Pages/Customer/Create.vue` | Create customer form |
| `Pages/Customer/Edit.vue` | Edit customer form |
| `Pages/Customer/Show.vue` | Customer detail with transaction history |
| `Pages/Customer/PrintStatement.vue` | Printable account statement |
| `Components/CustomerRelationship.vue` | Customer relationship display |
| `Components/CreateCustomerModal.vue` | Quick-create from POS |
| `Components/AddBalanceModal.vue` | Manual balance adjustment |

---

## Main Endpoints

### Customers (`/customers`)
- Full resource CRUD: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`
- `POST /customers/batch-destroy` — Bulk delete
- `GET /customers/{customer}/print-statement` — Account statement PDF/print
- `POST /customers/{customer}/adjust-balance` — Manual balance adjustment

### Customer Payments (`/customers/{customer}/payments`)
- `POST /customers/{customer}/payments` — Register payment on customer account

### Related Transaction Endpoints
- `GET /customers/{customer}/pending-debts` — Fetch unpaid transactions

---

## Balance & Credit System

### Key Fields on Customer
- `balance` (decimal) — Current outstanding debt (positive = owes money)
- `credit_limit` (decimal) — Maximum allowed credit
- Computed: `available_credit` = `credit_limit - balance`

### Balance Movements
Every change to a customer's balance creates a `CustomerBalanceMovement` record:
| Type | Trigger |
|---|---|
| `credit_usage` | Credit sale created |
| `payment` | Customer makes a payment |
| `refund_credit` | Transaction refunded (credit back) |
| `cancellation_credit` | Transaction cancelled (credit released) |
| `manual_adjustment` | Admin manually adjusts balance |

### Layaway Access
Customer model provides `layawayTransactions()` and `layawayItems()` scoped queries to find all layaway transactions and their items.

---

## Dependencies
- **Transactions/POS**: Customer is linked to transactions; credit sales affect balance
- **Branches**: Customers are scoped per branch via `HasSubscription` trait
- **Service Orders**: Customers can have service orders
- **Quotes**: Customers can have quotes
- **Invoices**: Customers receive invoices
- **Activity Log**: Customer changes logged via `LogsActivity`

---

## Known Limitations / Technical Debt
1. **No customer import duplicate detection** — Import doesn't check for duplicate emails/phones.
2. **No customer merge** — Cannot merge duplicate customer records.
3. **Balance is denormalized** — `balance` on the customer record must stay in sync with `CustomerBalanceMovement` sum. Direct DB updates could cause drift.
4. **No customer groups/segments** — No way to categorize customers into groups for targeted promotions.
5. **Credit limit is informational** — It's checked in UI but there's no hard block at the transaction level.
6. **Print statement is basic** — Uses a simple print view, not a proper PDF generation library.
