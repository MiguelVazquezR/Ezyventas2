# Ezyventas2 — Architectural Overview

> Generated: 2026-07-19 | Target audience: AI assistants needing codebase context

---

## 1. Tech Stack

| Layer | Technology | Version |
|---|---|---|
| **Backend** | Laravel (PHP) | 12.x (PHP ^8.2) |
| **Frontend** | Vue 3 (Composition API) + Inertia.js | Vue 3.3+, Inertia 2.x |
| **UI Library** | PrimeVue | 4.5.5 (auto-imported, no manual component registration) |
| **CSS** | Tailwind CSS | 3.4.x + `tailwindcss-primeui` plugin |
| **Build** | Vite | 7.x |
| **Auth** | Laravel Sanctum (SPA) + Jetstream 5.3 (Inertia stack) | Cookie-based session + API tokens |
| **Auth (Social)** | Laravel Socialite | Google OAuth |
| **Permissions** | Spatie Laravel Permission | 6.x — roles & permissions, kebab-case names |
| **Activity Log** | Spatie Laravel Activitylog | 4.x |
| **Media** | Spatie Laravel Media Library | 11.x (image uploads, conversions) |
| **Excel** | Maatwebsite Excel | 3.x (import/export) |
| **Payments** | MercadoPago PHP SDK | 3.x (Brazil/Argentina/Mexico gateway) |
| **WebSockets** | Pusher + Laravel Echo | Real-time broadcast (cash register sessions) |
| **Queue** | Laravel `database` driver | No Redis/Horizon |
| **Image Optim.** | TinyPNG (tinify) | On-upload compression |
| **Charts** | Chart.js | 4.x (dashboard graphs) |
| **Thermal Print** | Custom `PrintEncoderService` | ESC/POS + TSPL |
| **Search** | None (no Scout/Meilisearch) | Eloquent queries only |

---

## 2. Project Structure

```
Ezyventas2/
├── app/
│   ├── Actions/{Module}/         # Single-use-case orchestrators
│   ├── Enums/                    # 23 PHP backed enums (string)
│   ├── Exports/                  # Maatwebsite Excel export classes
│   ├── Http/
│   │   ├── Controllers/{Module}/ # Thin controllers, delegate to Actions
│   │   ├── Middleware/           # CheckOnboardingStatus, CheckSubscriptionStatus, etc.
│   │   └── Requests/{Module}/   # All validation in Form Requests
│   ├── Imports/                  # Excel import classes
│   ├── Models/                   # 61 Eloquent models
│   ├── Services/{Module}/        # Reusable business logic
│   └── Traits/                   # HasSubscription (multi-tenant), OptimizeMediaLocal
├── config/                       # Laravel config + spatie/permission/media-library/activitylog
├── database/
│   ├── migrations/               # ~100 migration files
│   └── seeders/                  # Database seeders
├── packages/ai-agent/            # Local Composer package (Ezyventas\AiAgent)
├── resources/js/
│   ├── Components/               # Reusable Vue components (modals, forms, ui)
│   ├── Composables/              # Vue composables (usePermissions, etc.)
│   ├── Layouts/                  # AppLayout.vue, AppSidebar, AppTopbar, AppMenu
│   └── Pages/{Module}/           # Inertia page components (~26 module dirs)
├── routes/
│   ├── web.php                   # Main route file (dashboard, onboarding, landing)
│   ├── web/{module}.php          # 41 route files, one per module/feature
│   ├── api.php                   # API routes
│   └── tienda.php                # Public online store routes
└── docs/
    └── context/                  # THIS documentation set
```

### Naming Conventions

| Context | Convention | Example |
|---|---|---|
| **Route files** | `routes/web/kebab-case.php` | `service-orders.php`, `cash-register-sessions.php` |
| **URL segments** | kebab-case | `/service-orders/{serviceOrder}` |
| **Named routes** | `module.action` | `service-orders.store`, `pos.index` |
| **Controllers** | PascalCase in `{Module}/` | `ServiceOrderController.php` |
| **Form Requests** | `Store*Request`, `Update*Request` | `StoreServiceOrderRequest.php` |
| **Actions** | `VerbEntityAction` | `CreateServiceOrderAction.php` |
| **Services** | `EntityService` | `FinancialReportService.php` |
| **Models** | PascalCase singular | `ServiceOrder.php` |
| **Vue Pages** | PascalCase in `Pages/{Module}/` | `Pages/ServiceOrder/Index.vue` |
| **Vue Components** | PascalCase in `Components/{Category}/` | `Components/Forms/CustomerSelect.vue` |
| **Permissions** | kebab-case | `create service-orders`, `edit invoices` |
| **DB tables** | snake_case plural | `service_orders`, `cash_register_sessions` |
| **Pivot tables** | snake_case singular_singular | `branch_product`, `bank_account_branch` |

---

## 3. Architectural Decisions

### Auth Strategy
- **Jetstream (Inertia stack)** for session-based SPA auth
- **Sanctum** for API tokens
- **Socialite** for Google OAuth login
- **Spatie Permission** for authorization (permissions checked in FormRequest `authorize()`)
- No multi-guard — single `web` guard, all users in `users` table

### State Management
- Inertia.js passes server-side data as page props (no Vuex/Pinia)
- Vue `useForm()` for all forms (Inertia form helper, auto-CSRF)
- Flash messages (`->with('success', ...)`) auto-displayed as toasts in `AppLayout.vue`

### API Style
- Inertia-based SPA (not a REST API for the main app)
- API routes in `routes/api.php` for external integrations
- Public store uses Inertia rendering (not a separate API frontend)

### Database ORM
- Eloquent with rich models (scopes, accessors, mutators, casts)
- Polymorphic relationships for `TransactionItem`, `QuoteItem`, `ServiceOrderItem` (morph to `Product`, `ProductAttribute`, `Service`, `ServiceVariant`)
- Polymorphic `settings` (morphTo on `SettingValue` for User/Branch/Subscription)
- No repository pattern — business logic in Services and Actions

### Multi-tenancy
- **No multi-tenancy package** (no stancl/tenancy)
- Manual scoping via `subscription_id` column + `HasSubscription` trait
- Each `Subscription` has `Branch`(es), each `Branch` has users/products/transactions
- Middleware `EnsureSubscriptionScope` enforces data isolation

### Deployment
- Standard Laravel deployment (no Docker config visible)
- Queue driver: `database` (no Redis/Horizon)
- File storage: local disk with `spatie/laravel-medialibrary`

---

## 4. Module Index

| # | Module | Context File | Description |
|---|---|---|---|
| 01 | Auth & Users | `02-module-auth-users.md` | Login, registration, 2FA, Google OAuth, user CRUD, roles & permissions, profile, API tokens |
| 02 | Subscriptions & Billing | `03-module-subscriptions.md` | SaaS subscription management, plan items, versions, MercadoPago payments, branches, onboarding |
| 03 | Products & Inventory | `04-module-products-inventory.md` | Products, categories, brands, providers, global catalog, variants/attributes, stock, composite kits |
| 04 | POS & Sales | `05-module-pos-sales.md` | Point of sale (desktop/mobile), transactions, payments, layaways, product exchanges |
| 05 | Customers | `06-module-customers.md` | Customer CRUD, balance, credit limits, account statements, balance movements |
| 06 | Services & Service Orders | `07-module-services-orders.md` | Services catalog, service variants, service orders with technician workflow, diagnosis |
| 07 | Quotes | `08-module-quotes.md` | Quote creation, versioning, status workflow, convert to sale, print |
| 08 | Invoices (CFDI) | `09-module-invoices.md` | Electronic invoicing (CFDI 4.0), billing settings, invoice cancellation |
| 09 | Expenses & Banking | `10-module-expenses-banking.md` | Expense tracking, expense categories, bank accounts, bank transfers |
| 10 | Cash Register | `11-module-cash-register.md` | Cash registers, session management (open/close), multi-user sessions, cash movements |
| 11 | Promotions | `12-module-promotions.md` | Promotional rules/effects, cart-level discounts, product-level promotions |
| 12 | Online Store | `13-module-online-store.md` | Public e-commerce storefront, MercadoPago checkout, order management, delivery |
| 13 | Reports & Dashboard | `14-module-reports-analytics.md` | Sales dashboard, financial reports, inventory reports, export capabilities |
| 14 | Admin & Settings | `15-module-admin-settings.md` | System settings, print templates, custom fields, import/export, quick create, super admin panel |
| 15 | Referrals | `16-module-referrals.md` | Referral program: codes, discounts, rewards, referrer bank accounts |
| 16 | AI Agent | `17-module-ai-agent.md` | AI chat assistant, usage tracking, tool system |
| 17 | Misc & Infrastructure | `18-module-misc.md` | Release notes, activity log, media library, waitlist, help center |

---

## 5. Key Middleware Stack

| Middleware | Purpose |
|---|---|
| `auth` | Core authentication |
| `auth:sanctum` | API token auth (used alongside session in Jetstream) |
| `verified` | Email verification required |
| `CheckOnboardingStatus` | Redirects un-onboarded users to setup wizard |
| `CheckSubscriptionStatus` | Blocks access if subscription is expired/suspended |
| `EnsureSubscriptionScope` | Scopes queries to current subscription |
| `ResolveStore` | Resolves store config from subdomain/slug for public store |
| `CheckSuperAdmin` | Ensures user has super-admin role for `/admin/*` routes |
| `HandleInertiaRequests` | Standard Inertia middleware (shared props) |

---

## 6. Common Patterns

### Controller → Action → Service Flow
```php
// Controller (thin)
public function store(StoreServiceOrderRequest $request): RedirectResponse
{
    $order = $this->createServiceOrderAction->execute($request->validated());
    return redirect()->route('service-orders.index')->with('success', 'Orden creada.');
}
```

### Polymorphic Items
`TransactionItem`, `QuoteItem`, and `ServiceOrderItem` all share a polymorphic `itemable` relationship that can reference `Product`, `ProductAttribute` (variant), `Service`, or `ServiceVariant`.

### Stock Management
Products track stock at the branch level via pivot models (`BranchProduct`, `BranchProductAttribute`). Stock changes use methods `processStockChange()` / `applyDirectStockChange()` for atomic updates.

### Permissions
All authorization uses Spatie permission names in kebab-case (e.g., `create service-orders`). Roles are scoped per branch. No Laravel Policies used — gates defined via Spatie.

### Flash Messages → Toasts
Controller flash messages (`->with('success', 'Mensaje')`) are automatically picked up by `AppLayout.vue` and displayed as PrimeVue toasts. No manual toast calls needed in Vue pages.
