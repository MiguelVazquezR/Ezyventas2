# 08 — Quotes Module

---

## What It Does
Quote/cotización management: create quotes with products and services, version tracking, status workflow (draft → sent → accepted/rejected), convert quote to sale, and print. Supports tax configuration, shipping costs, and custom fields.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/Quote.php` | Quote with versioning, status, totals |
| `app/Models/QuoteItem.php` | Line items (polymorphic to products/services) |
| `app/Enums/QuoteStatus.php` | Quote statuses |
| `app/Actions/Quote/StoreQuoteAction.php` | Create quote |
| `app/Actions/Quote/UpdateQuoteAction.php` | Update quote |
| `app/Actions/Quote/ChangeQuoteStatusAction.php` | Status transitions |
| `app/Actions/Quote/ConvertQuoteToSaleAction.php` | Quote → transaction |
| `app/Http/Controllers/QuoteController.php` | Quote CRUD + operations |
| `app/Http/Requests/StoreQuoteRequest.php` | Create validation |
| `app/Http/Requests/UpdateQuoteRequest.php` | Update validation |
| `app/Services/QuoteInvoiceReportService.php` | Quote reports |
| `app/Exports/QuotesExport.php` | Quote export |
| `routes/web/quotes.php` | Quote routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Quote/Index.vue` | Quote list |
| `Pages/Quote/Create.vue` | Create quote |
| `Pages/Quote/Edit.vue` | Edit quote |
| `Pages/Quote/Show.vue` | Quote detail |
| `Pages/Quote/Print.vue` | Print quote |
| `Pages/Template/CreateQuoteTemplate.vue` | Quote template designer |

---

## Main Endpoints

### Quotes (`/quotes`)
- Full resource CRUD: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`
- `POST /quotes/batch-destroy` — Bulk delete
- `PATCH /quotes/{quote}/status` — Update status
- `POST /quotes/{quote}/new-version` — Create new version of a quote
- `GET /quotes/{quote}/print` — Print view
- `POST /quotes/{quote}/convert-to-sale` — Convert to transaction

---

## Quote Versioning

Quotes support versioning via `parent_quote_id`:
- When a quote is revised, a new quote record is created with `parent_quote_id` pointing to the original
- `version_number` increments
- Old versions are preserved for audit

---

## Key Business Rules

1. **Tax configuration** per quote: `tax_type` (IVA, etc.) and `tax_rate` are stored on the quote.
2. **Shipping cost** is a flat field on the quote (not calculated from product weights).
3. **Quote expiry**: `expiry_date` is informational — no automatic expiry/cancellation.
4. **Convert to sale**: Creates a `Transaction` from the quote. The transaction links back to the quote via `transaction_id`.
5. **Line items polymorphic**: `QuoteItem.itemable` can be `Product`, `ProductAttribute`, `Service`, or `ServiceVariant`.

---

## Dependencies
- **Products/Inventory**: Products and variants appear as quote items
- **Services**: Services and variants appear as quote items
- **Customers**: Each quote links to a customer
- **Transactions**: Conversion creates a transaction
- **Service Orders**: Service orders can reference a source quote
- **Branches**: Scoped via `HasSubscription` trait
- **Print Templates**: Quotes can use custom print templates
- **Custom Fields**: Quotes support custom field definitions

---

## Known Limitations / Technical Debt
1. **No email sending** — Quotes are created but there's no "send to customer by email" feature.
2. **No e-signature or acceptance workflow** — Status is manually changed; no customer portal to accept/reject.
3. **Version comparison** — No side-by-side diff view for comparing quote versions.
4. **No automatic expiry** — Expired quotes aren't automatically cancelled.
5. **No discount-per-item** — Discounts are at the quote level (`total_discount`), not per line item.
