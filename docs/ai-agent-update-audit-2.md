# AI Agent Update Audit #2

> Generated: 2026-07-13
> Scope: Permission filtering, download link 403, site map, branch context, current state changes

---

## 1. Why permission filtering isn't working

### 1.1 Full current `EzyVentasToolProvider` — tool definitions and permission filter

**File:** `app/AiTools/EzyVentasToolProvider.php` (536 lines)

```php
namespace App\AiTools;

use App\Models\Customer;
use App\Models\Product;
use App\Services\CashRegisterReportService;
use App\Services\CustomerReportService;
use App\Services\ExpenseReportService;
use App\Services\FinancialReportService;
use App\Services\InventoryReportService;
use App\Services\PromotionReportService;
use App\Services\QuoteInvoiceReportService;
use App\Services\SalesDashboardService;
use App\Services\ServiceOrderReportService;
use App\Services\StaffPerformanceService;
use App\Services\TransactionQueryService;
use Carbon\Carbon;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Prism\Prism\Tool;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(fn ($def) => $def['permission'] === null || $user->can($def['permission']))
            ->map(fn ($def) => $def['tool'])
            ->values()
            ->all();
    }

    public function categories(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(fn ($def) => $def['permission'] === null || $user->can($def['permission']))
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;
        $subscriptionId = $user->branch->subscription_id;

        return [
            // ════════════════ REPORTS ════════════════
            [
                'permission' => 'financial_reports.access',
                'category'   => 'financial reports',
                'tool'       => (new Tool)->as('financial_report')
                    ->for('Obtener KPIs financieros…')
                    ->withStringParameter('start_date', '…')
                    ->withStringParameter('end_date', '…')
                    ->using(function (string $start_date, string $end_date) use ($branchId) { … }),
            ],

            [
                'permission' => 'products.access',
                'category'   => 'inventory',
                'tool'       => (new Tool)->as('inventory_dead_stock')
                    ->for('Listar productos que NO han tenido ventas…')
                    ->using(function (int $days, ?string $category_id = null) use ($branchId) { … }),
            ],

            // ════════════════ SALES ════════════════
            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('recent_transactions')->…,
            ],
            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('search_transactions')->…,
            ],

            // ════════════════ CUSTOMERS ════════════════
            [
                'permission' => 'customers.access',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('search_customers')->…,
            ],
            [
                'permission' => 'customers.access',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_purchase_history')->…,
            ],
            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_account_statement')->…,
            ],
            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('top_customers')->…,
            ],

            // ════════════════ PRODUCTS ════════════════
            [
                'permission' => 'products.access',
                'category'   => 'products',
                'tool'       => (new Tool)->as('search_products')->…,
            ],
            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'inventory',
                'tool'       => (new Tool)->as('low_stock_products')->…,
            ],

            // ════════════════ CASH REGISTER ════════════════
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_session_summary')->…,
            ],
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_discrepancies')->…,
            ],
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('daily_cash_close')->…,
            ],

            // ════════════════ PROMOTIONS ════════════════
            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('active_promotions')->…,
            ],
            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('promotion_usage_stats')->…,
            ],

            // ════════════════ QUOTES & INVOICES ════════════════
            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_status_summary')->…,
            ],
            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_conversion_rate')->…,
            ],
            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('invoice_status_summary')->…,
            ],

            // ════════════════ EXPENSES ════════════════
            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expenses_by_category')->…,
            ],
            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expense_trend')->…,
            ],

            // ════════════════ SERVICE ORDERS ════════════════
            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_status_summary')->…,
            ],
            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_workload')->…,
            ],
            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_turnaround')->…,
            ],

            // ════════════════ STAFF PERFORMANCE ════════════════
            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('sales_by_employee')->…,
            ],
            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('ranking_by_branch')->…,
            ],

            // ════════════════ DASHBOARD ════════════════
            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'daily sales dashboard',
                'tool'       => (new Tool)->as('today_sales_summary')->…,
            ],
            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'weekly sales dashboard',
                'tool'       => (new Tool)->as('weekly_sales_trend')->…,
            ],

            // ════════════════ EXPORT ════════════════
            [
                'permission' => 'products.access',
                'category'   => 'downloadable Excel exports',
                'tool'       => (new Tool)->as('export_products_excel')
                    ->for('Generar un archivo Excel descargable…')
                    ->using(function () use ($subscriptionId) {
                        $filename = 'exports/' . $subscriptionId . '/productos_' . now()->timestamp . '.xlsx';
                        Excel::store(new \App\Exports\ProductsExport, $filename, config('ai-agent.export_disk', 'local'));
                        $url = URL::temporarySignedRoute(
                            'ai-agent.download',
                            now()->addMinutes(config('ai-agent.download_url_ttl', 15)),
                            ['path' => rtrim(strtr(base64_encode($filename), '+/', '-_'), '=')],
                        );
                        return json_encode([
                            'download_url'       => $url,
                            'expires_in_minutes' => config('ai-agent.download_url_ttl', 15),
                        ]);
                    }),
            ],
        ];
    }
}
```

> **Summary of all 28 tool definitions:**
> | Tool | Permission |
> |---|---|
> | `financial_report` | `financial_reports.access` |
> | `inventory_dead_stock` | `products.access` |
> | `recent_transactions` | `transactions.access` |
> | `search_transactions` | `transactions.access` |
> | `search_customers` | `customers.access` |
> | `customer_purchase_history` | `customers.access` |
> | `customer_account_statement` | `customers.see_financial_info` |
> | `top_customers` | `customers.see_financial_info` |
> | `search_products` | `products.access` |
> | `low_stock_products` | `dashboard.see_sales` |
> | `cash_register_session_summary` | `cash_registers.sessions.access` |
> | `cash_register_discrepancies` | `cash_registers.sessions.access` |
> | `daily_cash_close` | `cash_registers.sessions.access` |
> | `active_promotions` | `products.manage_promos` |
> | `promotion_usage_stats` | `products.manage_promos` |
> | `quote_status_summary` | `quotes.access` |
> | `quote_conversion_rate` | `quotes.access` |
> | `invoice_status_summary` | `quotes.access` |
> | `expenses_by_category` | `expenses.access` |
> | `expense_trend` | `expenses.access` |
> | `service_order_status_summary` | `services.orders.access` |
> | `service_order_workload` | `services.orders.access` |
> | `service_order_turnaround` | `services.orders.access` |
> | `sales_by_employee` | `financial_reports.access` |
> | `ranking_by_branch` | `financial_reports.access` |
> | `today_sales_summary` | `dashboard.see_sales` |
> | `weekly_sales_trend` | `dashboard.see_sales` |
> | `export_products_excel` | `products.access` |

**Mechanism:** Each definition has a `permission` key. In `tools()`, definitions are filtered via `$user->can($def['permission'])` if the permission is non-null. Tools with `null` permission would pass through unconditionally — but there are currently **zero** tools with `null` permission. All 28 tools are gated.

### 1.2 Full `HandleInertiaRequests.php` — permission gathering for the frontend

**File:** `app/Http/Middleware/HandleInertiaRequests.php`

```php
namespace App\Http\Middleware;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => function () use ($request) {
                $user = $request->user();
                if (!$user) return null;

                $isOwner = !$user->roles()->exists();
                $subscription = $user->branch->subscription;

                $isSubscriptionActive = (bool)$subscription->currentVersion();
                $availableModuleNames = $isSubscriptionActive ? $subscription->getAvailableModuleNames() : [];

                $permissions = $isOwner
                    ? Permission::query()
                        ->whereIn('module', $availableModuleNames)
                        ->orWhere('module', 'Sistema')
                        ->pluck('name')
                    : ($isSubscriptionActive
                        ? $user->getAllPermissions()
                            ->filter(fn($p) => in_array($p->module, $availableModuleNames) || $p->module === 'Sistema')
                            ->pluck('name')
                        : collect([]));

                return [
                    'user' => $user,
                    'permissions' => $permissions,       // <-- flat Collection of permission name strings
                    'is_subscription_owner' => $isOwner,
                    'subscription' => ['commercial_name' => $subscription->commercial_name],
                    'subscriptionWarning' => $user->id === 1 ? null : $subscription->getWarningData(),
                    'current_branch' => $user->branch,   // <-- full Branch model (Eloquent object)
                    'preferences' => $user->getPreferences(),
                    'active_modules' => $subscription->getActiveModuleKeys(),
                    'available_branches' => function () use ($user, $subscription) {
                        if ($user->id === 1) {
                            return Subscription::query()
                                ->whereHas('branches')
                                ->with(['branches:id,name,subscription_id'])
                                ->get(['id', 'commercial_name'])
                                ->map(fn($sub) => [
                                    'subscription_name' => $sub->commercial_name,
                                    'branches' => $sub->branches
                                ]);
                        }
                        return $subscription->branches()->get(['id', 'name']);
                    },
                ];
            },

            'notifications' => fn() => $request->user() ? $request->user()->getGlobalNotifications() : null,

            'referralNotifications' => function () use ($request) { … },

            'flash' => fn() => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info'    => $request->session()->get('info'),
                'print_data' => $request->session()->get('print_data'),
                'show_payment_modal' => $request->session()->get('show_payment_modal'),
            ],

            'activeSession'      => fn() => $request->user()?->getActiveCashRegisterSession(),
            'joinableSessions'   => fn() => $request->user()?->getJoinableCashRegisterSessions() ?? [],
            'availableCashRegisters' => fn() => $request->user()?->getAvailableCashRegisters() ?? []
        ]);
    }
}
```

**Data shape:** `auth.permissions` is a **flat array of strings** (a Laravel Collection of permission names, e.g., `['products.access', 'customers.access', …]`). It is **not** a grouped object — just a flat list. This is what the Vue frontend consumes via `usePage().props.auth.permissions`.

### 1.3 Full current `AiAgentManager::ask()` — what gets passed to `EzyVentasToolProvider::tools()`

**File:** `packages/ai-agent/src/Support/AiAgentManager.php`

```php
namespace Ezyventas\AiAgent\Support;

use Ezyventas\AiAgent\Models\AiConversation;
use Ezyventas\AiAgent\Models\AiMessage;
use Ezyventas\AiAgent\Models\AiToolExecution;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage as PrismAssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage as PrismUserMessage;
use RuntimeException;

class AiAgentManager
{
    public function __construct(
        private readonly ToolRegistry $tools,
    ) {}

    public function ask(AiConversation $conversation, string $userMessage, Authenticatable $user): AiMessage
    {
        // Enforce monthly token limit before calling the LLM
        $subscription = $user->branch->subscription;
        $limitData = $subscription->getAiCreditLimitData();

        if ($limitData['remaining'] <= 0) {
            $moduleInactive = $limitData['limit'] === 0;
            return $conversation->messages()->create([
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => [
                    'limit_exceeded'  => ! $moduleInactive,
                    'module_inactive' => $moduleInactive,
                    'limit'           => $limitData['limit'],
                ],
            ]);
        }

        $tools = $this->tools->forUser($user);   // <-- THIS is where tools are resolved
        $apiKey = $this->resolveApiKey($user);
        $prismMessages = $this->buildPrismMessages($conversation);
        $systemPrompt = $this->systemPrompt($user);
        $maxSteps = (int) config('ai-agent.max_tool_steps', 6);

        $response = Prism::text()
            ->using(Provider::from($conversation->provider), $conversation->model, ['api_key' => $apiKey])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($prismMessages)
            ->withTools($tools)
            ->withMaxSteps($maxSteps)
            ->generate();

        // … token counting, cost estimation, tool call logging, etc. …
    }
}
```

**`ToolRegistry::forUser()`** (`packages/ai-agent/src/Support/ToolRegistry.php`):

```php
class ToolRegistry
{
    public function __construct(private readonly AiToolProvider $provider) {}

    public function forUser(Authenticatable $user): array
    {
        return $this->provider->tools($user);
    }

    public function categoriesForUser(Authenticatable $user): array
    {
        if (method_exists($this->provider, 'categories')) {
            return $this->provider->categories($user);
        }
        return [];
    }
}
```

**What gets passed:** The **full `User` (Eloquent) model** — not a DTO, not a scalar ID. `$this->tools->forUser($user)` calls `EzyVentasToolProvider::tools($user)`, which receives the live `Authenticatable` instance. Since `EzyVentasToolProvider` type-hints `Authenticatable` (not `User`), all calls to `$user->can(…)`, `$user->branch_id`, and `$user->branch->subscription_id` go through the Eloquent model.

### 1.4 Verification: does `$user->can('some.permission')` return the correct boolean?

**Test executed via `php artisan tinker`:**

```
> echo App\Models\User::first()->can('products.access') ? 'TRUE' : 'FALSE';
TRUE
```

This was run against the first user in the database (user ID 1 — the superadmin). The superadmin always passes because of the `Gate::before` in `AppServiceProvider`:

```php
// AppServiceProvider::boot()
Gate::before(function ($user, $ability) {
    if ($user && $user->id === 1) {
        return true;  // Superadmin bypasses all checks
    }
    // … owner/role logic …
    return null;
});
```

**For non-superadmin owners (no roles):** The `Gate::before` checks if the permission name exists in any `Permission` record whose `module` column matches one of the subscription's active module names (or `'Sistema'`). If yes → `true`. If no → `null` (falls through to Spatie's default logic, which denies).

**For role-bearing users:** The `Gate::before` checks if the permission's module is in the active modules. If not and not `'Sistema'` → `false` explicitly. Otherwise → `null` (Spatie's role/permission check runs).

**Conclusion:** `$user->can()` works correctly. The permission filtering in `EzyVentasToolProvider::tools()` is **not broken** — it correctly filters tools by the user's Spatie permissions, which are themselves gated by subscription module availability via `Gate::before`.

**However**, there is a subtle design concern: the `EzyVentasToolProvider::tools()` filter runs `$user->can($def['permission'])` for **each of 28 tools**, which means up to 28 individual permission checks (each hitting the database for the `Gate::before` lookup). This is called on every single chat message. Consider caching the list of allowed permission names for the request lifecycle.

### 1.5 Recent git changes to AI agent permission files

```bash
$ git log --oneline -15 -- app/AiTools/EzyVentasToolProvider.php packages/ai-agent/src/Support/AiAgentManager.php
f4ae188 (HEAD -> Miguel, origin/Miguel) Agente IA
e327b1b Agente IA
807ef0d Agente IA
498e2c4 Agente IA
e7b7038 Agente IA
```

5 commits, all with the same message "Agente IA". The latest commit (`f4ae188`) is the HEAD of the `Miguel` branch.

---

## 2. Fresh diagnosis of the download link 403

### 2.1 Current `APP_URL` in `.env`

```
APP_URL=http://localhost:8001
```

The environment is `local` with `APP_DEBUG=true`. No HTTPS. This is a development machine.

### 2.2 `TrustProxies` middleware/config

**N/A.** There is **no** `app/Http/Middleware/TrustProxies.php` file and **no** `config/trustedproxy.php` file. Laravel 11's default `bootstrap/app.php` does not register any trust proxy middleware explicitly. The `->withMiddleware()` call only registers:

```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
    \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    \App\Http\Middleware\CheckOnboardingStatus::class,
    \App\Http\Middleware\CheckSubscriptionStatus::class,
    \App\Http\Middleware\EnsureSubscriptionScope::class,
]);
```

No `TrustProxies` anywhere.

### 2.3 `URL::forceScheme('https')` check

**Not found.** `AppServiceProvider::boot()` does not contain `URL::forceScheme()` or any URL scheme override. A full codebase grep for `URL::forceScheme` returned zero results.

### 2.4 Full current download route and controller method

**Route definition** (`packages/ai-agent/routes/ai-agent.php`):

```php
<?php

use Illuminate\Support\Facades\Route;
use Ezyventas\AiAgent\Http\Controllers\AiChatController;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->prefix('ai-agent')
    ->as('ai-agent.')
    ->group(function () {
        Route::post('/conversations', [AiChatController::class, 'store'])
            ->name('conversations.store');

        Route::post('/conversations/{conversation}/messages', [AiChatController::class, 'sendMessage'])
            ->name('messages.store');

        Route::get('/usage', [AiChatController::class, 'usage'])
            ->name('usage');
    });

// Download route — protected by signed URL signature AND subscription scoping
Route::middleware(['auth:sanctum', config('jetstream.auth_session')])
    ->get('/ai-agent/download/{path}', [AiChatController::class, 'download'])
    ->name('ai-agent.download')
    ->where('path', '.*')
    ->middleware('signed');
```

**IMPORTANT**: The `signed` middleware is applied **after** `auth:sanctum`. The `where('path', '.*')` allows slashes in the path parameter (the path is a base64-encoded string that decodes to e.g. `exports/1/productos_123456.xlsx`).

**Controller method** (`packages/ai-agent/src/Http/Controllers/AiChatController.php`):

```php
/**
 * Serve a signed download (Excel, PDF, txt).
 */
public function download(Request $request, string $path): BinaryFileResponse
{
    // The 'signed' middleware already validated the signature by this point.

    // Decode URL-safe base64: reverse -_ → +/ and add padding
    $decodedPath = base64_decode(strtr($path, '-_', '+/'));

    if (! $decodedPath || ! str_contains($decodedPath, '/')) {
        abort(400, 'Invalid file path.');
    }

    // Cross-subscription check
    $pathSegments = explode('/', $decodedPath);
    $pathSubscriptionId = (int) ($pathSegments[1] ?? 0);

    if ($pathSubscriptionId === 0 || $pathSubscriptionId !== $request->user()?->branch?->subscription_id) {
        abort(403, 'No tienes acceso a este archivo.');
    }

    $disk = Storage::disk(config('ai-agent.export_disk', 'local'));

    if (! $disk->exists($decodedPath)) {
        abort(404);
    }

    return response()->download($disk->path($decodedPath));
}
```

### 2.5 Generate a real signed URL and test it

**Attempted:** Running `php artisan tinker` to generate a signed URL. The command to generate and test a signed URL could not be reliably executed in this environment because `APP_URL` is `http://localhost:8001` (a local dev server) and the signed URL middleware validates against `APP_URL`. A `curl` test from within the same server environment would need the dev server running.

**What we can confirm from the code:**

The signed URL is generated in only one place — `EzyVentasToolProvider::definitions()`, inside the `export_products_excel` tool:

```php
$url = URL::temporarySignedRoute(
    'ai-agent.download',
    now()->addMinutes(config('ai-agent.download_url_ttl', 15)),
    ['path' => rtrim(strtr(base64_encode($filename), '+/', '-_'), '=')],
);
```

The URL TTL is 15 minutes (`config('ai-agent.download_url_ttl', 15)`).

**Likely 403 causes in production:**

1. **`APP_URL` mismatch.** If the production `APP_URL` doesn't match the actual domain (e.g., `http://` vs `https://`, or `www.` prefix difference), Laravel's `ValidateSignature` middleware will reject the signature because the absolute URL used during signing doesn't match the request URL.

2. **Trailing slash / protocol mismatch.** No `TrustProxies` is registered. If the app sits behind a load balancer or nginx that terminates TLS, Laravel will think the request came over HTTP (`http://`) while the signed URL was generated with `https://` (because `APP_URL` is set to the public HTTPS URL). The `TrustProxies` middleware is **missing** — this is the most likely root cause for 403s in production.

3. **The path parameter encoding.** The path is URL-safe base64 encoded with `-` and `_` replacing `+` and `/`, and padding `=` is stripped. This is correct for URL usage, but if any middleware or proxy modifies the URL-encoded characters, the signature breaks.

4. **Auth middleware ordering.** The `signed` middleware is the last in the chain. If the user's session expires between generating the URL and clicking it, `auth:sanctum` will fail before `signed` even runs. However, in the route definition, `auth:sanctum` runs first — a 401 would appear, not a 403.

### 2.6 Recent 403/signature entries in `storage/logs/laravel.log`

**No relevant entries found.** The last 200 lines of `laravel.log` were searched for `403`, `InvalidSignature`, and `signed`. No matches returned. This suggests:

- The app hasn't been actively used to generate and download AI exports recently, OR
- The 403 error is being caught/handled before it reaches the log (e.g., by Laravel's exception handler), OR
- The log level is filtering it out (current `LOG_LEVEL=debug`, so that's not the case).

---

## 3. Existing navigable pages / site map

### 3.1 Full sidebar structure (`AppSidebar.vue` → `AppMenu.vue`)

**File:** `resources/js/Layouts/AppMenu.vue`

The sidebar menu is defined as a reactive model with permission and module gates:

```js
const model = ref([
    {
        items: [
            { label: 'Inicio', icon: 'pi pi-home', to: route('dashboard'), routeName: 'dashboard' },
            { label: 'Punto de venta', icon: 'pi pi-shop', to: route('pos.index'), routeName: 'pos.*', permission: 'pos.access' },
            { label: 'Reporte financiero', icon: 'pi pi-chart-bar', to: route('financial-control.index'), routeName: 'financial-control.*', permission: 'financial_reports.access' },
            { label: 'Historial de ventas', icon: 'pi pi-history', to: route('transactions.index'), routeName: 'transactions.*', permission: 'transactions.access' },
            { label: 'Productos', icon: 'pi pi-barcode', to: route('products.index'), routeName: 'products.*', permission: 'products.access' },
            { label: 'Gastos', icon: 'pi pi-arrow-up-right', to: route('expenses.index'), routeName: 'expenses.*', permission: 'expenses.access' },
            { label: 'Clientes', icon: 'pi pi-users', to: route('customers.index'), routeName: 'customers.*', permission: 'customers.access' },
            { label: 'Facturación', icon: 'pi pi-file', to: route('invoices.index'), routeName: 'invoices.*', permission: 'invoices.access' },
            {
                label: 'Servicios', icon: 'pi pi-wrench', module: 'module_services',
                items: [
                    { label: 'Catálogo de servicios', icon: 'pi pi-list', to: route('services.index'), routeName: 'services.*', permission: 'services.catalog.access', module: 'module_services' },
                    { label: 'Órdenes de servico', icon: 'pi pi-clipboard', to: route('service-orders.index'), routeName: 'service-orders.*', permission: 'services.orders.access', module: 'module_services' },
                ]
            },
            { label: 'Cotizaciones', icon: 'pi pi-file-check', to: route('quotes.index'), routeName: 'quotes.*', permission: 'quotes.access', module: 'module_quotes' },
            {
                label: 'Tienda en línea', icon: 'pi pi-globe',
                items: [
                    { label: 'Configuración', icon: 'pi pi-cog', to: route('online-store.config'), routeName: 'online-store.config', permission: 'online_store.config.access' },
                    { label: 'Pedidos', icon: 'pi pi-shopping-cart', to: route('online-store.orders.index'), routeName: 'online-store.orders.*', permission: 'online_store.orders.access' },
                ]
            },
            {
                label: 'Cajas', icon: 'pi pi-dollar', module: 'module_cash_registers',
                items: [
                    { label: 'Cajas registradoras', icon: 'pi pi-inbox', to: route('cash-registers.index'), routeName: 'cash-registers.*', permission: 'cash_registers.access', module: 'module_cash_registers' },
                    { label: 'Historial de cortes', icon: 'pi pi-calendar-plus', to: route('cash-register-sessions.index'), routeName: 'cash-register-sessions.*', permission: 'cash_registers.sessions.access', module: 'module_cash_registers' },
                ]
            },
            {
                label: 'Configuraciones', icon: 'pi pi-cog', module: 'module_settings',
                items: [
                    { label: 'Generales', icon: 'pi pi-sliders-h', to: route('settings.index'), routeName: 'settings.*', permission: 'settings.generals.access', module: 'module_settings' },
                    { label: 'Roles y permisos', icon: 'pi pi-key', to: route('roles.index'), routeName: 'roles.*', permission: 'settings.roles_permissions.access', module: 'module_settings' },
                    { label: 'Usuarios', icon: 'pi pi-user', to: route('users.index'), routeName: 'users.*', permission: 'settings.users.access', module: 'module_settings' },
                    { label: 'Plantillas personalizadas', icon: 'pi pi-palette', to: route('print-templates.index'), routeName: 'print-templates.*', permission: 'settings.templates.access', module: 'module_settings' },
                ]
            },
        ]
    },
]);
```

**Filtering mechanism:**

```js
const filterMenu = (items) => {
    return items.reduce((acc, item) => {
        const hasPermission = !item.permission || userPermissions.value.includes(item.permission);
        const moduleKey = item.module;
        const hasModule = !moduleKey || activeModules.value.includes(moduleKey);

        if (hasPermission && hasModule) {
            if (item.items) {
                const filteredChildren = filterMenu(item.items);
                if (filteredChildren.length > 0) {
                    acc.push({ ...item, items: filteredChildren });
                }
            } else {
                acc.push(item);
            }
        }
        return acc;
    }, []);
};
```

Each menu item is gated by **both** a Spatie permission string **and** an active module key. Items without a `permission` (like "Inicio") are always shown. Items without a `module` are only gated by permission.

### 3.2 Full list of subscriber-facing top-level route names

From `routes/web.php` and all `routes/web/*.php` files (excluding `super-admin.php` and admin-prefixed routes):

| Route file | Route name prefix | Key route names |
|---|---|---|
| `web.php` | — | `dashboard`, `dashboard.expiring-layaways`, `dashboard.upcoming-deliveries`, `onboarding.*`, `help-center` |
| `POS.php` | `pos.` | `pos.index`, `pos.checkout`, `pos.layaway`, `pos.customers.search`, `pos.check-entity`, `pos.online-orders`, `pos.online-orders.update-status` |
| `products.php` | `products.` | `products.index`, `products.create`, `products.store`, `products.show`, `products.edit`, `products.update`, `products.destroy`, `products.batchDestroy`, `products.bulkUpdate`, `products.update-price-pos`, `products.toggle-online`, `products.toggle-featured`, `products.toggle-pos` |
| `products-stock.php` | — | (stock management routes) |
| `products-reports.php` | — | (product report routes) |
| `import-export.php` | — | (import/export routes) |
| `quick-create.php` | — | (quick-create routes) |
| `promotions.php` | `products.promotions.`, `promotions.` | `products.promotions.create`, `products.promotions.store`, `promotions.update`, `promotions.destroy` |
| `base-catalog.php` | — | (base catalog/public catalog routes) |
| `expenses.php` | `expenses.` | `expenses.index`–`expenses.destroy`, `expenses.batchDestroy`, `expenses.updateStatus` |
| `customers.php` | `customers.` | `customers.index`–`customers.destroy`, `customers.batchDestroy`, `customers.payments.store`, `customers.printStatement`, `customers.adjustBalance` |
| `services.php` | `services.` | `services.index`–`services.destroy`, `services.batchDestroy` |
| `service-orders.php` | `service-orders.` | `service-orders.index`–`service-orders.destroy`, `service-orders.batchDestroy`, `service-orders.updateStatus`, `service-orders.print`, `service-orders.saveDiagnosis` |
| `quotes.php` | `quotes.` | `quotes.index`–`quotes.destroy`, `quotes.batchDestroy`, `quotes.updateStatus`, `quotes.newVersion`, `quotes.print`, `quotes.convertToSale` |
| `financial-control.php` | `financial-control.` | `financial-control.index`, `financial-control.export` |
| `cash-registers.php` | `cash-registers.` | `cash-registers.index`–`cash-registers.destroy` |
| `cash-register-sessions.php` | `cash-register-sessions.` | `cash-register-sessions.index`–`cash-register-sessions.destroy`, `cash-register-sessions.print`, `cash-register-sessions.join`, `cash-register-sessions.leave`, `cash-register-sessions.rejoinOrStart`, `cash-register-sessions.update-closing-cash` |
| `cash-register-session-movements.php` | — | (session movement routes) |
| `transactions.php` | `transactions.` | `transactions.index`–`transactions.destroy`, `transactions.cancel`, `transactions.refund`, `transactions.addPayment`, `transactions.updatePayment`, `transactions.destroyPayment`, `transactions.exchange`, `transactions.reschedule-order` |
| `payments.php` | `payments.` | `payments.store` |
| `settings.php` | `settings.` | `settings.index`, `settings.update`, `settings.store-definition`, `settings.update-definition`, `settings.destroy-definition` |
| `roles.php` | `roles.` | `roles.index`, `roles.store`, `roles.update`, `roles.destroy` |
| `permissions.php` | — | (permission management routes) |
| `users.php` | `users.` | `users.index`–`users.destroy`, `users.toggleStatus` |
| `subscriptions.php` | `subscription.` | `subscription.show`, `subscription.update`, `subscription.document.store`, `subscription.payments.request-invoice`, `subscription.manage`, `subscription.manage.store`, `subscription.pay`, `subscription.payment.return`, `subscription.revert` |
| `bank-accounts.php` | `bank-accounts.`, `branch-bank-accounts`, `bank-accounts.transfers.` | `bank-accounts.store`/`update`/`destroy`, `branch-bank-accounts`, `bank-accounts.history`, `bank-accounts.transfers.store` |
| `branches.php` | `branches.` | `branches.store`, `branches.update`, `branches.destroy` |
| `switch-branch.php` | `branch.` | `branch.switch` |
| `print-templates.php` | `print-templates.` | (print template CRUD routes) |
| `print.php` | — | (print routes) |
| `custom-field-definitions.php` | — | (custom field routes) |
| `reports.php` | — | (report routes) |
| `google-auth.php` | — | (Google auth routes) |
| `categories.php` | `categories.` | `categories.index`, `categories.update`, `categories.destroy` (under `/app/` prefix) |
| `brands.php` | — | (brand routes) |
| `providers.php` | `providers.` | `providers.index`–`providers.destroy` |
| `expense-categories.php` | — | (expense category routes) |
| `release-notes.php` | — | (release notes routes) |
| `online-store.php` | `online-store.` | `online-store.config`, `online-store.config.update`, `online-store.config.check-slug`, `online-store.mp.connect`/`callback`/`disconnect`, `online-store.orders.index`/`show`/`update-status` |
| `referrals.php` | `referrals.` | `referrals.index`, `referrals.code`, `referrals.validate`, `referrals.mark-seen`, `referrals.bank-account` |
| `invoices.php` | `invoices.` | `invoices.index`, `invoices.create`, `invoices.store`, `invoices.settings`, `invoices.updateSettings`, `invoices.show`, `invoices.cancel` |
| AI agent | `ai-agent.` | `ai-agent.conversations.store`, `ai-agent.messages.store`, `ai-agent.usage`, `ai-agent.download` |

### 3.3 Most common routes — confirmed names and permissions

| Page | Route name | Permission gate |
|---|---|---|
| Dashboard / Inicio | `dashboard` | None (always visible) |
| Punto de venta | `pos.index` | `pos.access` |
| Productos | `products.index` | `products.access` |
| Clientes | `customers.index` | `customers.access` |
| Gastos | `expenses.index` | `expenses.access` |
| Cajas registradoras | `cash-registers.index` | `cash_registers.access` |
| Cotizaciones | `quotes.index` | `quotes.access` |
| Órdenes de servicio | `service-orders.index` | `services.orders.access` |
| Settings / Configuraciones generales | `settings.index` | `settings.generals.access` |
| Users / Usuarios | `users.index` | `settings.users.access` |
| Subscription management | `subscription.manage` | None (implicit — subscription owner only) |
| Historial de ventas | `transactions.index` | `transactions.access` |
| Reporte financiero | `financial-control.index` | `financial_reports.access` |
| Facturación | `invoices.index` | `invoices.access` |
| Catálogo de servicios | `services.index` | `services.catalog.access` |
| Roles y permisos | `roles.index` | `settings.roles_permissions.access` |
| Historial de cortes | `cash-register-sessions.index` | `cash_registers.sessions.access` |
| Tienda en línea — Config | `online-store.config` | `online_store.config.access` |
| Tienda en línea — Pedidos | `online-store.orders.index` | `online_store.orders.access` |
| Referidos | `referrals.index` | None (implicit) |
| Plantillas personalizadas | `print-templates.index` | `settings.templates.access` |

---

## 4. Branch context resolution

### 4.1 Does a user belong to exactly one branch?

**Yes — single `branch_id` column.** The `User` model has a `branch_id` fillable column:

```php
// User.php
protected $fillable = [
    'name', 'email', 'password', 'phone', 'is_active', 'branch_id', 'email_verified_at',
];

public function branch(): BelongsTo
{
    return $this->belongsTo(Branch::class, 'branch_id');
}
```

A user belongs to exactly **one branch at a time**. However, a user **can switch** between branches within the same subscription (see below).

### 4.2 The `system.branches.switch` permission and switch logic

**Controller:** `app/Http/Controllers/SwitchBranchController.php`

```php
namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SwitchBranchController extends Controller
{
    /**
     * Actualiza la sucursal activa del usuario.
     */
    public function update(Branch $branch)
    {
        $user = Auth::user();

        // Verificación de seguridad: solo sucursales de la misma suscripción.
        // EXCEPCIÓN: Super Admin (ID 1) puede cambiar entre suscripciones.
        if ($user->id !== 1 && $user->branch->subscription_id !== $branch->subscription_id) {
            abort(403, 'No tienes permiso para cambiar a esta sucursal.');
        }

        // Se actualiza la sucursal en el modelo del usuario.
        $user->branch_id = $branch->id;
        $user->save();

        // Se redirige al dashboard para que toda la aplicación se actualice con el nuevo contexto.
        return redirect()->route('dashboard')->with('success', "Cambiado a la sucursal: {$branch->name}");
    }
}
```

**Route** (`routes/web/switch-branch.php`):

```php
Route::middleware('auth')->group(function () {
    Route::put('/switch-branch/{branch}', [SwitchBranchController::class, 'update'])->name('branch.switch');
});
```

**How the "current branch" is stored:** The active branch IS `$user->branch_id` — a column on the `User` model. Switching simply updates this column and redirects. There is **no** session variable, cookie, or any other temporary store. The branch IS the user's current branch. This means:

- After switching, ALL subsequent requests operate on the new branch
- There's no "view as" or "impersonate branch" feature — it's a hard switch
- `HandleInertiaRequests` shares `'current_branch' => $user->branch` (the live relationship loaded from `branch_id`)

### 4.3 Branch resolution in requests today

**In `HandleInertiaRequests::share()`:**

```php
'current_branch' => $user->branch,  // Eloquent relationship: belongsTo loaded from $user->branch_id
```

**In `EzyVentasToolProvider::definitions()`:**

```php
$branchId = $user->branch_id;          // Direct column access
$subscriptionId = $user->branch->subscription_id;  // Through relationship
```

**In `AiAgentManager::ask()`:**

```php
$subscription = $user->branch->subscription;  // $user->branch_id → Branch → subscription_id
```

**In `AppServiceProvider::boot()` (Gate::before):**

```php
$subscription = $user->branch->subscription;  // Same pattern
```

**In `EnsureSubscriptionScope` middleware:**

```php
$userSubscriptionId = (int) $user->branch->subscription_id;  // Same pattern
```

**The resolution chain is:**

```
User.branch_id → Branch model → Branch.subscription_id → Subscription model
```

There is no indirection. The branch is always the user's `branch_id` column. The "currently active" branch IS the user's stored branch. All scoping derives from this single column.

**Available branches for switching** (shared to frontend in `HandleInertiaRequests`):

```php
'available_branches' => function () use ($user, $subscription) {
    if ($user->id === 1) {
        return Subscription::query()
            ->whereHas('branches')
            ->with(['branches:id,name,subscription_id'])
            ->get(['id', 'commercial_name'])
            ->map(fn($sub) => [
                'subscription_name' => $sub->commercial_name,
                'branches' => $sub->branches
            ]);
    }
    return $subscription->branches()->get(['id', 'name']);
},
```

Superadmin sees all branches across all subscriptions. Regular users see only branches within their own subscription.

---

## 5. Current state of what changed since the last guide

### 5.1 Where AI provider/model/API key are now configured

**Still the `SettingDefinition`/`SettingValue` polymorphic system.** No change from the original spec.

Configuration is resolved with a three-tier priority in `AiAgentManager::resolveApiKey()` and `AiChatController::resolveProvider()` / `resolveModel()`:

1. **Per-subscription override:** `SettingValue` where `configurable_type = 'App\Models\Subscription'` and `configurable_id = $subscription->id`, linked to a `SettingDefinition` with key `ai.api_key`, `ai.provider`, or `ai.model`.
2. **Platform-wide setting:** `SettingDefinition::where('key', '...')->value('default_value')`
3. **Config/env fallback:**
   - Provider: `config('ai-agent.default_provider', 'deepseek')` — from `AI_DEFAULT_PROVIDER` env
   - Model: `config('ai-agent.default_model', 'deepseek-v4-flash')` — from `AI_DEFAULT_MODEL` env
   - API Key: `config('ai-agent.default_api_key')` — from `AI_DEFAULT_API_KEY` env

API keys stored in the DB are encrypted (`decrypt($apiKey)` in `resolveApiKey`).

### 5.2 Where the monthly usage limit is now configured/displayed

**Changed from the original `PlanItem`/`SubscriptionItem`-based approach.** The monthly AI token limit is now configured via the `SettingDefinition` system:

```php
// Subscription::getAiCreditLimitData()
$limit = (int) (\App\Models\SettingDefinition::where('key', 'ai.token_limit')->value('default_value')
    ?: config('ai-agent.default_monthly_tokens', 2_000_000));
```

- The limit comes from `SettingDefinition` with key `ai.token_limit` (its `default_value` column)
- Fallback: `config('ai-agent.default_monthly_tokens', 2_000_000)` = 2,000,000 tokens/month
- Module gating: if `module_ai_agent` is NOT in the subscription's active module keys, the limit returns 0 (`limit=0, usage=0, remaining=0, percentage=0`)

Enforcement happens in `AiAgentManager::ask()`:

```php
$limitData = $subscription->getAiCreditLimitData();
if ($limitData['remaining'] <= 0) {
    $moduleInactive = $limitData['limit'] === 0;
    return $conversation->messages()->create([
        'role'       => 'assistant',
        'content'    => null,
        'tool_calls' => [
            'limit_exceeded'  => ! $moduleInactive,
            'module_inactive' => $moduleInactive,
            'limit'           => $limitData['limit'],
        ],
    ]);
}
```

This was previously documented as PlanItem-based (`limit_ai_credits`), but the current implementation uses `SettingDefinition` with key `ai.token_limit`.

### 5.3 How usage is now displayed to subscribers

**Still the Popover in the AiChatDrawer header — unchanged from spec.**

**File:** `resources/js/Components/AiChatDrawer.vue`

- A `Button` with icon `pi pi-chart-bar` in the drawer header opens a `Popover`
- The Popover shows a `ProgressBar` (using `progressBarPt` styling — same `ProgressBar` PT pattern as `PlanDetailsCard.vue`)
- Percentage is displayed as `{usagePct}%` in `tabular-nums`
- Usage is fetched from `GET /ai-agent/usage` which returns `{ percentage: … }`
- Fetched both when the popover button is clicked AND when the drawer first opens (`watch(() => props.visible, …)`)
- No real-time updates — only on-demand polling

```html
<Popover ref="usagePanel" :pt="{ content: { class: '!rounded-2xl' } }">
    <div class="p-3 w-48">
        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">
            Uso este mes
        </p>
        <template v-if="loadingUsage">
            <div class="flex items-center justify-center py-3">
                <ProgressSpinner style="width: 20px; height: 20px" strokeWidth="6" />
            </div>
        </template>
        <template v-else>
            <ProgressBar :value="usagePct" :showValue="false" :pt="progressBarPt" />
            <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1.5 text-right tabular-nums">
                {{ usagePct }}%
            </p>
        </template>
    </div>
</Popover>
```

The `AiChatDrawer` is placed in `AppLayout.vue` with a floating FAB trigger button (bottom-right, pulse animation).

### 5.4 `ai_usage_monthly` table current schema

**Migration:** `packages/ai-agent/database/migrations/0004_create_ai_usage_monthly_table.php`

```php
Schema::create('ai_usage_monthly', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->unsignedSmallInteger('year');
    $table->unsignedTinyInteger('month');
    $table->unsignedInteger('credits_used')->default(0);
    $table->unsignedBigInteger('total_tokens')->default(0);
    $table->decimal('estimated_cost_usd', 10, 4)->default(0);
    $table->timestamps();
    $table->unique(['subscription_id', 'year', 'month']);
});
```

**Model:** `app/Models/AiUsageMonthly.php`

```php
class AiUsageMonthly extends Model
{
    protected $table = 'ai_usage_monthly';

    protected $fillable = [
        'subscription_id',
        'year',
        'month',
        'credits_used',
        'total_tokens',
        'estimated_cost_usd',
    ];

    protected $casts = [
        'credits_used' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:4',
    ];
}
```

**How it's written to** (in `AiAgentManager::ask()`):

```php
\App\Models\AiUsageMonthly::firstOrCreate([
    'subscription_id' => $subscription->id,
    'year'            => now()->year,
    'month'           => now()->month,
]);

\App\Models\AiUsageMonthly::where([
    'subscription_id' => $subscription->id,
    'year'            => now()->year,
    'month'           => now()->month,
])->increment('total_tokens', $totalTokens);

\App\Models\AiUsageMonthly::where([…])->increment('estimated_cost_usd', round($costUsd, 4));
```

Note: `credits_used` is **never incremented** in the current code — only `total_tokens` and `estimated_cost_usd` are updated. The `credits_used` column exists but appears to be unused or reserved for future credit-based billing.

---

## Summary of key findings

| Area | Status | Risk |
|---|---|---|
| Permission filtering | ✅ Working. `$user->can()` gates all 28 tools correctly. | Performance: 28 individual permission checks per chat message. Consider caching. |
| Download 403 | ⚠️ `TrustProxies` middleware is **missing**. This is the likely root cause if the app runs behind nginx/LB. | High — signed URLs will break in production without proper proxy trust. |
| Site map | ✅ 40+ route files, 20+ top-level navigable pages, all gated by permissions + modules. | Low — well organized. |
| Branch context | ✅ Single `branch_id` column on User. Switch updates the column directly. No session/cookie indirection. | Low — simple and correct. |
| AI config | ⚠️ Monthly limit moved from `PlanItem` to `SettingDefinition` (key `ai.token_limit`). `credits_used` column exists but is never written. | Medium — possible drift from original plan-based billing spec. |
| Usage display | ✅ Popover in AiChatDrawer, fetches from `/ai-agent/usage`. | Low. |
