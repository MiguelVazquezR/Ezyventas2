# 10 — Expenses & Banking Module

---

## What It Does
Expense tracking with categorization, bank account management with balance tracking, and inter-account transfers. Expenses can be paid from cash (session cash movements) or bank accounts.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/Expense.php` | Expense with status, payment method, category |
| `app/Models/ExpenseCategory.php` | Expense categories scoped to subscription |
| `app/Models/BankAccount.php` | Bank account with balance + deposit/withdraw methods |
| `app/Models/BankAccountTransfer.php` | Inter-account transfer |
| `app/Enums/ExpenseStatus.php` | Expense statuses |
| `app/Actions/Expense/StoreExpenseAction.php` | Create expense |
| `app/Actions/Expense/UpdateExpenseAction.php` | Update expense |
| `app/Http/Controllers/ExpenseController.php` | Expense CRUD |
| `app/Http/Controllers/ExpenseCategoryController.php` | Category CRUD |
| `app/Http/Controllers/BankAccountController.php` | Bank account + transfer CRUD |
| `app/Http/Requests/StoreExpenseRequest.php` | Expense validation |
| `app/Http/Requests/UpdateExpenseRequest.php` | Expense validation |
| `app/Services/ExpenseReportService.php` | Expense analytics |
| `app/Exports/ExpensesExport.php` | Expense export |
| `routes/web/expenses.php` | Expense routes |
| `routes/web/expense-categories.php` | Category routes |
| `routes/web/bank-accounts.php` | Bank account routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Expense/Index.vue` | Expense list |
| `Pages/Expense/Create.vue` | Create expense |
| `Pages/Expense/Edit.vue` | Edit expense |
| `Pages/Expense/Show.vue` | Expense detail |
| `Components/ManageExpenseCategoriesModal.vue` | Inline category management |
| `Components/BankAccountModal.vue` | Bank account create/edit modal |
| `Components/BankAccountTransferModal.vue` | Transfer between accounts modal |
| `Components/BankAccountHistoryModal.vue` | Account transaction history |

---

## Main Endpoints

### Expenses (`/expenses`)
- Full resource CRUD: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`
- `POST /expenses/batch-destroy` — Bulk delete
- `PATCH /expenses/{expense}/status` — Update expense status

### Expense Categories (`/expense-categories`)
- `GET /expense-categories` — `expense-categories.index`
- `PUT /expense-categories/{expenseCategory}` — `expense-categories.update`
- `DELETE /expense-categories/{expenseCategory}` — `expense-categories.destroy`

### Bank Accounts (`/bank-accounts`)
- `POST /bank-accounts` — `bank-accounts.store`
- `PUT /bank-accounts/{bankAccount}` — `bank-accounts.update`
- `DELETE /bank-accounts/{bankAccount}` — `bank-accounts.destroy`
- `GET /branch-bank-accounts` — List accounts for current branch
- `GET /bank-accounts/{bankAccount}/history` — Transaction history
- `POST /bank-accounts/transfers` — Create transfer between accounts

---

## Key Business Rules

1. **Expense payment sources**: An expense can be paid from a bank account (`bank_account_id`) or from the cash register (`session_cash_movement_id`). If paid from cash, a `SessionCashMovement` is created.
2. **External expenses**: `is_external` flag marks expenses unrelated to operations (e.g., owner's personal expense).
3. **Bank balance tracking**: `BankAccount` has `deposit()` and `withdraw()` methods that update the balance. Transfers debit one account and credit another.
4. **Expense folio**: Auto-generated sequential number for tracking.
5. **Expense status workflow**: Typically `pendiente` → `pagado` (paid), but can also be cancelled.

---

## Dependencies
- **Cash Register**: Expenses can create session cash movements
- **Subscriptions**: Bank accounts are scoped to subscription; expense categories are scoped to subscription
- **Branches**: Expenses are scoped to branch; bank accounts can be linked to branches via pivot
- **Activity Log**: Expense status changes are logged

---

## Known Limitations / Technical Debt
1. **No recurring expenses** — All expenses are one-time; no subscription-like recurring expense templates.
2. **No bank reconciliation** — Bank balance is manually tracked; no import of bank statements for reconciliation.
3. **No receipt scanning** — Expense receipts aren't captured; the model doesn't use Spatie Media Library for attachments.
4. **Transfer between subscriptions not supported** — Only intra-subscription transfers.
5. **No expense approval workflow** — Expenses are created directly without multi-level approval.
