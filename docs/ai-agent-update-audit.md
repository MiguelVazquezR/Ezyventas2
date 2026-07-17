# AI Agent Update Audit — EzyVentas 2

> Generated: 2026-07-13 | Auditor: DeepSeek V4 Pro (VS Code Copilot)
> Scope: Six planned AI-agent feature updates. All file paths are relative to workspace root `c:\Users\Miguel\Desktop\Sitios web\Ezyventas2`.

---

## 1. System Prompt & Date/Time Handling

### 1.1 Full `systemPrompt()` method

**File:** `packages/ai-agent/src/Support/AiAgentManager.php` (lines 157-180)

```php
private function systemPrompt(Authenticatable $user): string
{
    $businessName = $user->branch?->subscription?->business_name ?? 'EzyVentas';

    return "You are the reporting assistant for {$businessName}, "
        . 'a point-of-sale business. Answer only using tool results. '
        . "If a question requires data you don't have a tool for, say so — never invent numbers. "
        . 'Respond in the same language the user writes in. '
        . 'You can answer questions about: '
        . 'financial reports (KPIs, sales by channel, expenses by category), '
        . 'inventory (dead stock, low stock), '
        . 'transactions (recent, filtered by status/payment/channel/date), '
        . 'customers (search, purchase history, account statements, top spenders), '
        . 'products (search by name/SKU), '
        . 'cash register sessions (session summaries, discrepancies, daily close), '
        . 'promotions (active promotions, usage stats), '
        . 'quotes and invoices (status summaries, quote-to-sale conversion rate), '
        . 'expenses (by category, monthly trends), '
        . 'service orders (status summary, technician workload, turnaround time), '
        . 'staff performance (sales by employee, branch rankings), '
        . 'and daily/weekly sales dashboards. '
        . 'You can also generate downloadable Excel exports of the product catalog.';
}
```

### 1.2 Is current date/time injected?

**No.** No current date, time, timezone, or `now()` is ever injected into the system prompt or any user/assistant message. The only reference to time is in the tool descriptions (e.g., `'Fecha inicial en formato YYYY-MM-DD'`), but there is no `Carbon::now()` or `date()` call that enriches the prompt with "today is…" or "current datetime is…". The AI only knows time from parameters the user types or from tool results that embed dates.

### 1.3 `config('app.timezone')`

**File:** `config/app.php` line 66

```php
'timezone' => 'America/Mexico_City',
```

### 1.4 Prism vs. custom provider

**Prism is installed and in use.** The migration happened.

**File:** `composer.json` lines 29-30

```json
"prism-php/prism": "0.100",
```

The `AiAgentManager::ask()` uses `Prism\Prism\Facades\Prism::text()` → `Provider::from(...)` → `generate()`. No custom `DeepSeekProvider` or manual HTTP client remains. The namespace `Prism\Prism\Enums\Provider` is used to resolve the provider enum dynamically from the conversation's `provider` column.

---

## 2. AI Response Metadata (token usage)

### 2.1 Raw Prism response shape — token usage availability

**File:** `packages/ai-agent/src/Support/AiAgentManager.php` lines 32-47

```php
$response = Prism::text()
    ->using(Provider::from($conversation->provider), $conversation->model, ['api_key' => $apiKey])
    ->withSystemPrompt($systemPrompt)
    ->withMessages($prismMessages)
    ->withTools($tools)
    ->withMaxSteps($maxSteps)
    ->generate();

// Only `->text` and `->steps` are consumed
$finalContent = $response->text ?: 'Lo siento, el asistente no pudo generar una respuesta...';
```

The Prism `generate()` response object exposes `->text` (string) and `->steps` (array of step objects, each with `->toolCalls` and `->toolResults`). There is **no** `->usage`, `->tokenUsage`, `->promptTokens`, `->completionTokens`, or `->totalTokens` property accessed today. The code does not read token counts at all.

Prism's underlying `EchoLabs\Prism\Providers\DeepSeek\DeepSeek` provider **does** receive `usage` in the raw API response (`prompt_tokens`, `completion_tokens`, `total_tokens`) from DeepSeek's `/chat/completions` endpoint, but this is discarded by the `generate()` method unless `->withClientOptions()` or the response's `->response` property is inspected.

### 2.2 Current DB schema for AI tables

**File:** `packages/ai-agent/database/migrations/0001_create_ai_conversations_table.php`

```php
Schema::create('ai_conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained();
    $table->string('title')->nullable();
    $table->string('provider');   // e.g. "deepseek"
    $table->string('model');      // e.g. "deepseek-v4-flash"
    $table->timestamps();
});
```

**File:** `packages/ai-agent/database/migrations/0002_create_ai_messages_table.php`

```php
Schema::create('ai_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['user', 'assistant', 'tool']);
    $table->longText('content')->nullable();
    $table->json('tool_calls')->nullable();  // audit log, not API-level tool_call data
    $table->timestamps();
});
```

**File:** `packages/ai-agent/database/migrations/0003_create_ai_tool_executions_table.php`

```php
Schema::create('ai_tool_executions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subscription_id')->constrained();
    $table->foreignId('user_id')->constrained();
    $table->string('tool_name');
    $table->json('arguments');
    $table->json('result')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->timestamps();
});
```

**Key observation:** There are no `prompt_tokens`, `completion_tokens`, `total_tokens`, or `cost` columns on any of these tables. Token usage is not persisted today.

---

## 3. Subscription & Plan Model

### 3.1 Full `Subscription` model

**File:** `app/Models/Subscription.php` (464 lines)

```php
class Subscription extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'business_name', 'business_type_id', 'commercial_name', 'status',
        'contact_phone', 'contact_email', 'tax_id', 'address', 'slug',
        'onboarding_completed_at', 'referrer_discount_active',
    ];

    protected $casts = [
        'address' => 'array',
        'onboarding_completed_at' => 'datetime',
        'referrer_discount_active' => 'boolean',
        'status' => SubscriptionStatus::class,
    ];

    protected $appends = ['computed_status'];
```

**Relationships:**
- `branches(): HasMany`
- `storeConfig(): HasOne`
- `versions(): HasMany` → `SubscriptionVersion`
- `payments(): HasManyThrough` → via `SubscriptionVersion`
- `users(): HasManyThrough` → via `Branch`
- `cashRegisters(): HasManyThrough` → via `Branch`
- `products(): HasManyThrough` → via `Branch`
- `services(): HasManyThrough` → via `Branch`
- `printTemplates(): HasMany`
- `bankAccounts(): HasMany`
- `expenses(): HasManyThrough` → via `Branch`
- `settings(): MorphMany` → `SettingValue` (polymorphic via `configurable`)
- `referralUsageAsReferred(): HasOne`

**Key computed properties/helpers:**
- `getComputedStatusAttribute()` — ACTIVE/EXPIRED/SUSPENDED based on latest version end_date
- `currentVersion()` — version active right now (start_date ≤ now ≤ end_date)
- `getActiveModuleKeys(): array`
- `hasReachedProductLimit()`, `hasReachedServiceLimit()`, `hasReachedUserLimit()`
- `getUserLimitData(): array` — returns `['limit' => int, 'usage' => int]`
- `getWarningData(): ?array` — expiration warnings (5-day threshold)
- `getStatusData(): array`
- `getCurrentMonthlyCost(): float`
- `getVersionsWithComparison()` — upgrade/downgrade diff
- `getReferrerActiveDiscountPct(): float`

### 3.2 Plan model — `PlanItem` (not a separate `Plan`)

There is **no** `Plan` or `SubscriptionPlan` model. Plans are composed from individual `PlanItem` rows (modules + limits).

**File:** `app/Models/PlanItem.php`

```php
class PlanItem extends Model
{
    protected $fillable = ['key', 'type', 'name', 'description', 'monthly_price', 'is_active', 'meta'];

    protected $casts = [
        'type' => PlanItemType::class,       // 'module' or 'limit'
        'monthly_price' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',                   // e.g. {"quantity": 100, "icon": "pi pi-barcode"}
    ];
}
```

**Supporting version-layer models:**
- `SubscriptionVersion`: `subscription_id`, `start_date`, `end_date` → `hasMany SubscriptionItem`, `hasMany SubscriptionPayment`
- `SubscriptionItem`: `subscription_version_id`, `item_key`, `item_type`, `name`, `quantity`, `unit_price`, `billing_period`
- `SubscriptionPayment`: `subscription_version_id`, `amount`, `payment_method`, `status`, `payment_details` (json), etc.

**Current catalog (from seeder):**

**File:** `database/seeders/PlanItemSeeder.php`

Modules: `module_pos` ($130/mo), `module_financial_reports` ($25), `module_transactions` ($0), `module_products` ($0), `module_expenses` ($0), `module_customers` ($30), `module_services` ($50), `module_quotes` ($35), `module_cash_registers` ($0), `module_settings` ($0).

Limits: `limit_branches` ($30/pkg), `limit_users` ($7.50/pkg), `limit_products` ($1.50/100pkg), `limit_services` ($1.50/100pkg), `limit_cash_registers` ($7.50/pkg), `limit_print_templates` ($3/pkg).

### 3.3 "Suscripciones" admin module (super-admin only)

**Inertia pages:**
- `resources/js/Pages/Admin/Subscriptions/Index.vue` — list of all subscribers
- `resources/js/Pages/Admin/Subscriptions/Show.vue` — subscription details with version history, limits, modules, settings

**Controller:** `app/Http/Controllers/Admin/SubscriptionController.php`

Key methods: `index()` (paginated list with search/filter/status), `show($id)` (full detail with dynamic limits, modules, version comparison, settings, plan value).

### 3.4 How "super admin only" is enforced

**File:** `app/Http/Middleware/CheckSuperAdmin.php`

```php
class CheckSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->branch && $user->branch->subscription_id === 1) {
            return $next($request);
        }

        abort(403, 'No tienes permiso para acceder a esta sección.');
    }
}
```

**Applied in routes:** `routes/web/super-admin.php`

```php
Route::middleware(['auth', CheckSuperAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    // All admin routes here
});
```

This is a **hardcoded subscription_id === 1 check**, not a role or permission check. It does not use Spatie permissions at all. The user with `subscription_id === 1` is the SaaS platform owner.

---

## 4. Renewal / Upgrade Flow

### 4.1 Controller and pages

**Frontend controller:** `app/Http/Controllers/SubscriptionController.php`

- `show()` → `resources/js/Pages/Subscription/Show.vue` — subscription status, plan details, payment history
- `manage()` → `resources/js/Pages/Subscription/ManageSubscription.vue` — intelligent upgrade/renew page
- `processManagement()` → delegates to `ProcessSubscriptionPaymentAction`
- `pay()` → Mercado Pago redirect
- `paymentReturn()` → handles MP callback
- `revert()` → `RevertFailedSubscriptionAction`

**Frontend subscriber guard:** All subscriber-side methods check `if ($user->roles()->exists()) abort(403)`. The owner (no roles) can access; employees (with roles) cannot.

### 4.2 "Add-ons" or "extras" concept

There is **no** separate "add-ons" entity. The system uses `PlanItem` with `type = 'module'` or `type = 'limit'` as the catalog. When a subscriber renews/upgrades, they select which items and quantities they want, and a `SubscriptionVersion` is created with corresponding `SubscriptionItem` rows. The `ProcessSubscriptionPaymentAction` handles both "upgrade" and "renew" modes.

### 4.3 Payment processor

**Mercado Pago** is the integrated processor (confirmed by `mercadopago/dx-php: ^3.10` in `composer.json` and the `mp_access_token` env vars).

**File:** `app/Http/Controllers/SubscriptionController.php` — `pay()` method uses `PlatformMercadoPagoService@createPreference()`

**File:** `app/Actions/Subscription/ProcessSubscriptionPaymentAction.php` — orchestrates both `transferencia` (manual bank transfer with proof upload) and `mercadopago` (deferred: payment created first, version created later on approval).

### 4.4 What triggers on plan change

**On transferencia payment:** `ProcessSubscriptionPaymentAction::handleTransferPayment()`:
- Creates/updates `SubscriptionVersion` + items
- Creates `SubscriptionPayment` with `PENDING` status
- Creates an `Expense` entry (if bank_account_id provided)
- Uploads proof_of_payment media
- Sends `AdminNewPaymentNotification` email to admin
- Applies referral discounts if code provided

**On MercadoPago approval** (`ApproveSubscriptionPaymentAction::execute()`):
- Creates version if deferred (MP flow)
- Sets payment status to `APPROVED`
- Sets subscription status to `ACTIVE`
- Liquidates the associated expense (PENDING → PAID, decrements bank balance)
- Processes referral system: `ProcessReferralOnPaymentApprovedAction`, `UpdateReferrerOngoingDiscountAction`, `GenerateReferralCodeAction`

No webhook is triggered by the application itself (other than the MP external webhook for payment confirmation). No custom events, jobs, or emails fire synchronously beyond the referral processing.

---

## 5. Permissions System (full detail)

### 5.1 Role model

**File:** `app/Models/Role.php`

```php
class Role extends SpatieRole
{
    // Overrides create() to support branch_id scoping and avoid global duplicate errors
    public static function create(array $attributes = []): RoleContract
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? Guard::getDefaultName(static::class);
        $params = ['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']];
        // ... teams support, branch_id scoping ...
    }
}
```

Extends `Spatie\Permission\Models\Role`. No custom columns beyond Spatie defaults + the `branch_id` scoping override.

### 5.2 Complete permission list

**File:** `database/seeders/PermissionSeeder.php` — all permission keys:

| Module | Permissions |
|--------|-------------|
| **Punto de Venta** | `pos.access`, `pos.create_sale`, `pos.edit_prices` |
| **Historial de Ventas** | `transactions.access`, `transactions.see_details`, `transactions.refund`, `transactions.cancel`, `transactions.add_payment`, `transactions.exchange`, `transactions.edit_payment`, `transactions.delete` |
| **Productos** | `products.access`, `products.see_details`, `products.create`, `products.edit`, `products.delete`, `products.manage_stock`, `products.manage_promos`, `products.manage_global_products`, `products.see_cost_price`, `products.import_export` |
| **Gastos** | `expenses.access`, `expenses.see_all`, `expenses.see_details`, `expenses.create`, `expenses.edit`, `expenses.delete`, `expenses.import_export`, `expenses.change_status`, `expenses.manage_categories` |
| **Clientes** | `customers.access`, `customers.see_details`, `customers.create`, `customers.edit`, `customers.delete`, `customers.store_sale`, `customers.import_export`, `customers.see_financial_info` |
| **Servicios** | `services.catalog.access`, `services.catalog.see_details`, `services.catalog.create`, `services.catalog.edit`, `services.catalog.delete`, `services.catalog.import_export`, `services.orders.access`, `services.orders.see_details`, `services.orders.create`, `services.orders.edit`, `services.orders.delete`, `services.orders.import_export`, `services.orders.change_status`, `services.orders.see_customer_info`, `services.orders.see_financial_info`, `services.orders.manage_custom_fields`, `services.print_tickets`, `services.print_etiquetas` |
| **Cotizaciones** | `quotes.access`, `quotes.see_details`, `quotes.create`, `quotes.edit`, `quotes.delete`, `quotes.export`, `quotes.change_status`, `quotes.create_sale`, `quotes.manage_custom_fields` |
| **Reportes financieros** | `financial_reports.access` |
| **Cajas** | `cash_registers.access`, `cash_registers.manage`, `cash_registers.sessions.access`, `cash_registers.sessions.create_movements`, `cash_registers.sessions.edit_movements`, `cash_registers.sessions.delete_movements` |
| **Configuraciones** | `settings.generals.access`, `settings.generals.update_branch`, `settings.generals.update_subscription`, `settings.roles_permissions.access`, `settings.roles_permissions.manage`, `settings.roles_permissions.delete`, `settings.users.access`, `settings.users.create`, `settings.users.edit`, `settings.users.delete`, `settings.users.change_status`, `settings.templates.access`, `settings.templates.create`, `settings.templates.edit`, `settings.templates.delete` |
| **Tienda en línea** | `online_store.config.access`, `online_store.config.edit`, `online_store.orders.access`, `online_store.orders.see_details`, `online_store.orders.change_status` |
| **Sistema** | `system.branches.switch`, `system.bank_accounts.manage`, `dashboard.see_sales`, `dashboard.see_layaways`, `dashboard.see_orders`, `dashboard.see_outstanding_balances`, `dashboard.see_inventory_details` |
| **Agente IA** | `ai_agent.access`, `ai_agent.export` |

Total: **89 permissions** across 12 modules.

### 5.3 Example controller permission check

N/A — this codebase does **not** use `can:` middleware or `$this->authorize()` in controllers. Instead:

- The subscriber-side controllers (`SubscriptionController`) check `if ($user->roles()->exists()) abort(403)` — owner-only gate
- The admin controllers use `CheckSuperAdmin` middleware (`subscription_id === 1`)
- There are no `can:module.action` middleware uses found in route files

### 5.4 Programmatic `$user->can()` outside HTTP context

**File:** `app/Models/User.php` line 226

```php
// Example in User model — works outside HTTP context
if ($this->roles()->exists() && !$this->can('transactions.access')) {
    // ...
}
```

The `User` model uses `Spatie\Permission\Traits\HasRoles`. The `can()` method works anywhere the User model is available — CLI commands, queued jobs, tool registrations. The Spatie package registers the gate before authorization checks. Confirmed working via the `AiAgentManager` flow: `$this->tools->forUser($user)` passes the authenticated user to `EzyVentasToolProvider::tools()`, which receives the full User model with `HasRoles`.

### 5.5 Full current `EzyVentasToolProvider::tools()` — complete list

**File:** `app/AiTools/EzyVentasToolProvider.php` (approx. 420 lines)

Complete tool list (21 tools):

| # | Tool name | Category |
|---|-----------|----------|
| 1 | `financial_report` | Reports |
| 2 | `inventory_dead_stock` | Reports |
| 3 | `recent_transactions` | Sales |
| 4 | `search_transactions` | Sales |
| 5 | `search_customers` | Customers |
| 6 | `customer_purchase_history` | Customers |
| 7 | `customer_account_statement` | Customers |
| 8 | `top_customers` | Customers |
| 9 | `search_products` | Products |
| 10 | `low_stock_products` | Products |
| 11 | `cash_register_session_summary` | Cash Register |
| 12 | `cash_register_discrepancies` | Cash Register |
| 13 | `daily_cash_close` | Cash Register |
| 14 | `active_promotions` | Promotions |
| 15 | `promotion_usage_stats` | Promotions |
| 16 | `quote_status_summary` | Quotes & Invoices |
| 17 | `quote_conversion_rate` | Quotes & Invoices |
| 18 | `invoice_status_summary` | Quotes & Invoices |
| 19 | `expenses_by_category` | Expenses |
| 20 | `expense_trend` | Expenses |
| 21 | `service_order_status_summary` | Service Orders |
| 22 | `service_order_workload` | Service Orders |
| 23 | `service_order_turnaround` | Service Orders |
| 24 | `sales_by_employee` | Staff Performance |
| 25 | `ranking_by_branch` | Staff Performance |
| 26 | `today_sales_summary` | Dashboard |
| 27 | `weekly_sales_trend` | Dashboard |
| 28 | `export_products_excel` | Export |

All 28 tools derive `$branchId` and/or `$subscriptionId` from `$user` server-side — never from LLM-supplied parameters. The contract is `Ezyventas\AiAgent\Contracts\AiToolProvider` which requires `tools(Authenticatable $user): array`.

---

## 6. Download Link 403 Bug

### 6.1 Download route definition

**File:** `packages/ai-agent/routes/ai-agent.php` lines 22-26

```php
// Download route — protected by signed URL signature, not by session auth
Route::get('/ai-agent/download/{path}', [AiChatController::class, 'download'])
    ->name('ai-agent.download')
    ->where('path', '.*')
    ->middleware('signed');
```

### 6.2 Download controller method

**File:** `packages/ai-agent/src/Http/Controllers/AiChatController.php` lines 82-101

```php
public function download(Request $request, string $path): BinaryFileResponse
{
    if (! $request->hasValidSignature()) {
        abort(401);
    }

    // Decode URL-safe base64: reverse -_ → +/ and add padding
    $path = base64_decode(strtr($path, '-_', '+/'));

    if (! $path || ! str_contains($path, '/')) {
        abort(400, 'Invalid file path.');
    }

    $disk = Storage::disk(config('ai-agent.export_disk', 'local'));

    if (! $disk->exists($path)) {
        abort(404);
    }

    return response()->download($disk->path($path));
}
```

### 6.3 Cross-subscription validation

**There is NO cross-subscription validation in the download route.** The only security is the Laravel signed URL middleware (`->middleware('signed')`). This verifies the URL hasn't been tampered with and hasn't expired (15 min TTL), but it does **not** check that the requesting user belongs to the subscription whose data is in the file.

If a signed URL is shared/copied to another user (even on a different subscription), that user can download the file within the TTL window. The signed URL is the sole gate.

### 6.4 File path construction in export tool

**File:** `app/AiTools/EzyVentasToolProvider.php` — `export_products_excel` tool closure

```php
(new Tool)->as('export_products_excel')
    ->for('Generar un archivo Excel descargable con el catálogo completo de productos')
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
```

The path is `exports/{subscriptionId}/productos_{timestamp}.xlsx`. The `subscriptionId` is embedded in the path, but the download controller never validates it against the requesting user. The `ProductsExport` class (`app/Exports/ProductsExport.php`) uses `Auth::user()->branch->subscription_id` to scope data — but that only scopes what's *in* the file, not who can *download* it.

### 6.5 Log evidence of 403

**N/A.** The `storage/logs/laravel.log` was not accessible in this session. However, the audit notes that if a 403 were to occur on this route, it would have to come from the `abort(401)` (invalid signature — misnamed as 401 instead of 403) or `abort(400)` (malformed path) or `abort(404)` (file not found). There is no `abort(403)` for cross-subscription access in this flow.

### 6.6 Git log for cross-subscription protection

**N/A.** No cross-subscription protection file was found. The download route has no dedicated middleware for subscription scoping. The git log command could not be executed in this session, but there is no identifiable "cross-subscription protection" file to trace.

---

## 7. Chat Drawer Frontend (current state)

### 7.1 Full `AiChatDrawer.vue`

**File:** `resources/js/Components/AiChatDrawer.vue` (195 lines)

```vue
<script setup>
import { ref, nextTick, watch } from 'vue';
import { useAiChat } from '@/composables/useAiChat';
import ProgressSpinner from 'primevue/progressspinner';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Drawer from 'primevue/drawer';
import Divider from 'primevue/divider';

const props = defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

const { messages, isThinking, sendMessage } = useAiChat();

const inputText = ref('');
const messagesContainer = ref(null);

watch(
    () => messages.value.length,
    async () => {
        await nextTick();
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    }
);

async function handleSend() {
    const text = inputText.value.trim();
    if (!text || isThinking.value) return;
    inputText.value = '';
    await sendMessage(text);
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
}

function renderContent(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>')
        .replace(
            /(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" class="text-primary-500 underline">$1</a>'
        );
}
</script>

<template>
    <Drawer
        :visible="visible"
        position="right"
        :style="{ width: '420px' }"
        :pt="{
            root: { class: '!bg-white dark:!bg-[#232323] !rounded-l-3xl !shadow-2xl' },
            header: { class: '!bg-white dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a]' },
            content: { class: '!bg-white dark:!bg-[#232323] !p-0' },
        }"
        @update:visible="emit('update:visible', $event)"
    >
        <template #header>
            <div class="flex items-center gap-3 w-full">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center flex-shrink-0">
                    <i class="pi pi-sparkles !text-white !text-sm" />
                </div>
                <div>
                    <h3 class="m-0 text-sm font-semibold text-gray-900 dark:text-white">Asistente IA</h3>
                    <p class="m-0 text-[10px] uppercase tracking-widest font-bold text-gray-500">EzyVentas AI</p>
                </div>
            </div>
        </template>

        <!-- Messages area -->
        <div ref="messagesContainer" class="flex flex-col gap-3 p-4 overflow-y-auto" :style="{ height: 'calc(100vh - 12rem)' }">
            <!-- Empty state -->
            <div v-if="messages.length === 0 && !isThinking" class="flex flex-col items-center justify-center h-full text-center px-4">
                <div class="w-16 h-16 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center mb-4">
                    <i class="pi pi-sparkles !text-2xl !text-primary-500" />
                </div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 m-0 mb-1">¿En qué puedo ayudarte?</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Pregúntame sobre ventas, inventario, clientes o pídeme que genere reportes.</p>
            </div>

            <!-- Messages -->
            <template v-for="(msg, i) in messages" :key="i">
                <div v-if="msg.role === 'user'" class="flex justify-end">
                    <div class="max-w-[80%] rounded-2xl rounded-br-md px-4 py-2.5 bg-primary-500 text-white text-sm">
                        {{ msg.content }}
                    </div>
                </div>

                <Transition name="fade-in">
                    <div v-if="msg.role === 'assistant' && msg.visible" class="flex justify-start">
                        <div class="max-w-[85%] rounded-2xl rounded-bl-md px-4 py-2.5 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-sm text-gray-800 dark:text-gray-200">
                            <div class="chat-content prose prose-sm max-w-none" v-html="renderContent(msg.content)" />
                        </div>
                    </div>
                </Transition>
            </template>

            <!-- Thinking indicator -->
            <div v-if="isThinking" class="flex justify-start">
                <div class="rounded-2xl rounded-bl-md px-5 py-3 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] flex items-center gap-2">
                    <ProgressSpinner style="width: 18px; height: 18px" strokeWidth="6" animationDuration="0.8s" />
                    <span class="text-xs text-gray-500">Pensando...</span>
                </div>
            </div>
        </div>

        <Divider class="!m-0" />

        <!-- Input area -->
        <div class="p-3">
            <div class="flex gap-2">
                <InputText v-model="inputText" placeholder="Escribe tu mensaje..." class="flex-1" :disabled="isThinking"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm' } }"
                    @keydown="onKeydown" />
                <Button icon="pi pi-send" :loading="isThinking" :disabled="!inputText.trim() || isThinking"
                    class="!rounded-full !w-10 !h-10 !p-0 flex-shrink-0" @click="handleSend" />
            </div>
            <p class="text-[10px] text-gray-400 m-0 mt-1.5 text-center">
                El asistente puede cometer errores. Verifica la información importante.
            </p>
        </div>
    </Drawer>
</template>
```

**Composable:** `resources/js/Composables/useAiChat.js`

The composable manages:
- `conversationId` (ref, persisted across drawer open/close, reset on page navigation)
- `messages` (ref array with `{role, content, tool_calls, visible}`)
- `isThinking` (ref)
- `ensureConversation()` — creates conversation via POST `/ai-agent/conversations`
- `sendMessage()` — optimistic user message, then POST to `/ai-agent/conversations/{id}/messages`, animates assistant reply
- `reset()` — clears state on page navigation

**Where the drawer is invoked:** The drawer is mounted in the app layout (likely `AppLayout.vue` or similar) and toggled via a floating action button. The `visible` prop is controlled by the parent.

### 7.2 Existing progress-bar / usage-meter pattern

**Yes.** The `PlanDetailsCard.vue` component uses PrimeVue's `ProgressBar` for usage meters.

**File:** `resources/js/Pages/Subscription/Partials/PlanDetailsCard.vue` lines 49-53, 131

```js
const progressBarPt = {
    root: { class: '!h-1.5 !bg-gray-200 dark:!bg-[#2a2a2a] !rounded-full overflow-hidden mt-3' },
    value: { class: '!bg-blue-500' }
};
```

```html
<ProgressBar v-if="limit.quantity > 0"
    :value="Math.round((getUsage(limit) / limit.quantity) * 100)"
    :showValue="false"
    :pt="progressBarPt" />
```

Also, `ExportProductsModal.vue` uses `<ProgressBar mode="indeterminate" class="w-full !h-1" />` for loading states.

This is the established pattern: PrimeVue `ProgressBar` with Tesla UI `:pt` overrides. A usage meter in the AI chat drawer should follow this same pattern for consistency.
