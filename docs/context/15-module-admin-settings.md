# 15 — Admin & Settings Module

---

## What It Does
Central system configuration: dynamic settings system (key-value with definitions), print template management (receipts, tickets, labels, quotes), custom field definitions for entities, quick-create endpoints for modal-based inline creation, and the super-admin panel for SaaS management (plan items, subscriptions, AI agent settings, release notes).

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/SettingDefinition.php` | Setting schema (key, type, module) |
| `app/Models/SettingValue.php` | Setting values (polymorphic per user/branch/subscription) |
| `app/Models/PrintTemplate.php` | Print template with type/context/content |
| `app/Models/CustomFieldDefinition.php` | Custom field schemas |
| `app/Enums/TemplateType.php` | Template types |
| `app/Enums/TemplateContextType.php` | Template contexts (ticket, label, quote, etc.) |
| `app/Http/Controllers/SettingsController.php` | Settings CRUD |
| `app/Http/Controllers/PrintTemplateController.php` | Print template CRUD |
| `app/Http/Controllers/PrintController.php` | Print payload generation |
| `app/Http/Controllers/CustomFieldDefinitionController.php` | Custom field CRUD |
| `app/Http/Controllers/QuickCreateController.php` | Inline quick create |
| `app/Services/PrintEncoderService.php` | ESC/POS + TSPL thermal printing |
| `app/Http/Controllers/Admin/PlanItemController.php` | Plan catalog CRUD |
| `app/Http/Controllers/Admin/SubscriptionController.php` | Admin subscription management |
| `app/Http/Controllers/Admin/SubscriptionPaymentController.php` | Admin payment review |
| `app/Http/Controllers/Admin/ReportController.php` | Admin reports |
| `app/Http/Controllers/Admin/ReleaseNoteController.php` | Release note CRUD |
| `app/Http/Controllers/Admin/AiAgentSettingsController.php` | AI agent config |
| `app/Http/Controllers/Admin/AdminReferralController.php` | Admin referral management |
| `routes/web/settings.php` | Settings routes |
| `routes/web/print-templates.php` | Template routes |
| `routes/web/print.php` | Print payload route |
| `routes/web/custom-field-definitions.php` | Custom field routes |
| `routes/web/quick-create.php` | Quick create routes |
| `routes/web/super-admin.php` | Admin panel routes |
| `routes/web/release-notes.php` | Release note routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Setting/Index.vue` | System settings page |
| `Pages/Template/Index.vue` | Template list |
| `Pages/Template/CreateTicket.vue` | Ticket template designer |
| `Pages/Template/CreateLabel.vue` | Label template designer |
| `Pages/Template/CreateQuoteTemplate.vue` | Quote template designer |
| `Pages/Admin/PlanItems/Index.vue` | Plan catalog |
| `Pages/Admin/PlanItems/Create.vue` | Create plan item |
| `Pages/Admin/PlanItems/Edit.vue` | Edit plan item |
| `Pages/Admin/Subscriptions/Index.vue` | All subscriptions |
| `Pages/Admin/Subscriptions/Show.vue` | Subscription detail |
| `Pages/Admin/Payment/Index.vue` | Payment review list |
| `Pages/Admin/Payment/Show.vue` | Payment detail |
| `Pages/Admin/Referral/Index.vue` | Referral usage list |
| `Pages/Admin/Referral/Settings.vue` | Referral settings |
| `Pages/Admin/Reports/Index.vue` | Admin reports |
| `Pages/Admin/ReleaseNotes/Index.vue` | Release notes list |
| `Pages/Admin/ReleaseNotes/Create.vue` | Create release note |
| `Pages/Admin/ReleaseNotes/Edit.vue` | Edit release note |
| `Pages/Admin/ReleaseNotes/Show.vue` | Release note detail |
| `Pages/Admin/AiAgent/Settings.vue` | AI agent settings |
| `Components/PrintModal.vue` | Print dialog |
| `Components/ManageCustomFields.vue` | Custom field editor |

---

## Main Endpoints

### Settings (`/settings`)
- `GET /settings` — `settings.index` — View all settings
- `POST /settings/values` — `settings.update` — Save settings
- `POST /settings/definition` — Create new setting definition
- `PUT /settings/definition/{setting}` — Update definition
- `DELETE /settings/definition/{setting}` — Delete definition

### Print Templates (`/print-templates`)
- Full resource CRUD + `POST /print-templates/media` for template images
- `PATCH /print-templates/{template}/toggle-default` — Set as default

### Print (`/print`)
- `POST /print/payload` — Generate ESC/POS or TSPL payload for thermal printers

### Custom Fields (`/custom-field-definitions`)
- Resource CRUD: `index`, `store`, `update`, `destroy`

### Quick Create (`/quick-create`)
- `POST /quick-create/categories` — Create category inline
- `POST /quick-create/brands` — Create brand inline
- `POST /quick-create/providers` — Create provider inline
- `POST /quick-create/expense_categories` — Create expense category inline
- `POST /quick-create/customers` — Create customer inline
- `POST /quick-create/products` — Create product inline
- `POST /quick-create/roles` — Create role inline

### Super Admin Panel (`/admin/*`)
See [03-module-subscriptions.md](03-module-subscriptions.md) for plan/subscription admin endpoints. Additional:
- `GET /admin/reports` — Admin reports
- Full CRUD: `/admin/release-notes` — Release note management
- `GET/PUT /admin/ai-agent` — AI agent global settings
- Referral management — see [16-module-referrals.md](16-module-referrals.md)

---

## Settings System Architecture

### Definition-Value Pattern
- `SettingDefinition` defines the schema: `key`, `name`, `module`, `level` (user/branch/subscription), `type` (string, boolean, select, etc.), `default_value`
- `SettingValue` stores actual values using polymorphic `settable` (can attach to `User`, `Branch`, or `Subscription`)
- This allows per-tenant, per-branch, and per-user settings overrides

---

## Print System

### Template Types
- Ticket (receipt)
- Label (product labels)
- Quote (cotización printout)

### Print Encoder Service
Generates raw print commands for:
- **ESC/POS** — Common thermal receipt printers (Epson, etc.)
- **TSPL** — Label printers (TSC, etc.)

The `PrintController@generatePayload` endpoint returns the encoded payload that the frontend sends to the printer via WebUSB or network.

---

## Dependencies
- **All modules**: Settings apply globally; templates used by POS, quotes, service orders
- **Subscriptions**: Settings can be per-subscription
- **Media Library**: Templates can include images

---

## Known Limitations / Technical Debt
1. **Print system is browser-dependent** — Uses WebUSB which only works in Chromium-based browsers.
2. **Template designer is basic** — No drag-and-drop editor; templates are JSON-based.
3. **No settings import/export** — Settings must be configured manually per tenant.
4. **Quick create is limited** — Only 7 entity types supported; adding a new one requires new controller method + route + modal.
5. **Super-admin panel is monolithic** — All admin routes are in one file (`super-admin.php`); no modular separation per sub-module.
