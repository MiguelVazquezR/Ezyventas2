# AI Agent Current State — EzyVentas 2

> Audited on 2026-07-12.

---

## 1. Current AI Integration Method

### Is `prism-php/prism` installed?

**No.** `prism-php/prism` is not in `composer.json` or `composer.lock`. A `composer require` was attempted but failed because:

```
prism-php/prism v0.1.0 requires php ^8.3
```

The project runs **PHP 8.2.12** (`php -v`), and `composer.json` requires `"php": "^8.2"`. Prism is incompatible with this PHP version.

### How DeepSeek is called today

The project uses **raw Guzzle HTTP client** pointed at `https://api.deepseek.com/v1/` (OpenAI-compatible endpoint). No `openai-php/client` package is installed either.

The full class making the API call is `packages/ai-agent/src/Providers/DeepSeekProvider.php`:

```php
namespace Ezyventas\AiAgent\Providers;

use GuzzleHttp\Client;

class DeepSeekProvider implements AiProvider
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.deepseek.com/v1/',
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout' => 120,
        ]);
    }

    public function chat(string $model, string $systemPrompt, array $messages, array $tools, string $apiKey): AiProviderResponse
    {
        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...array_map(fn (array $msg) => $this->formatMessageForApi($msg), $messages),
        ];

        $body = [
            'model'      => $model,
            'messages'   => $formattedMessages,
            'max_tokens' => 4096,
        ];

        if (! empty($tools)) {
            $body['tools'] = array_map(fn (Tool $t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t->name,
                    'description' => $t->description,
                    'parameters'  => $t->toProviderSchema()['input_schema'] ?? [],
                ],
            ], $tools);
        }

        $response = $this->http->post('chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $apiKey],
            'json'    => $body,
        ]);
        // ... parses choices[0].message.tool_calls into AiProviderResponse
    }
}
```

### Tool/function calling payload structure

Tools are sent as OpenAI-compatible `tools` array in the request body:

```json
{
  "model": "deepseek-chat",
  "messages": [...],
  "max_tokens": 4096,
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "recent_transactions",
        "description": "Obtener las transacciones más recientes...",
        "parameters": {
          "type": "object",
          "properties": {
            "limit": { "type": "number", "description": "Cantidad máxima..." }
          },
          "required": ["limit"]
        }
      }
    }
  ]
}
```

`tool_choice` is NOT sent — DeepSeek decides autonomously whether to call a tool.

### Tool-call response parsing & round-trip

1. Response parsed in `DeepSeekProvider::chat()`:
```php
$toolCalls = [];
foreach ($message['tool_calls'] ?? [] as $tc) {
    $toolCalls[] = [
        'id'        => $tc['id'],
        'name'      => $tc['function']['name'],
        'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
    ];
}
```

2. Second round-trip handled by `AiAgentManager::ask()` with a `while ($step < $maxSteps)` loop:
```php
while ($step < $maxSteps) {
    $response = $provider->chat(...);
    if (empty($response->toolCalls)) {
        $finalContent = $response->content;
        break;
    }
    $messages[] = $this->formatAssistantToolUseMessage($response);
    foreach ($response->toolCalls as $toolCall) {
        $result = $tool->execute($toolCall['arguments']);
        $messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $toolCall['id'],
            'content'      => $result,
        ];
    }
}
```

3. `formatMessageForApi()` in `DeepSeekProvider` transforms the internal tool_call format to OpenAI-compatible structure (tool results with `tool_call_id`, assistant messages with `tool_calls` → `{id, type:'function', function:{name, arguments}}`).

---

## 2. Current Tool Registry

### Tool definition file

`app/AiTools/EzyVentasToolProvider.php` — implements `Ezyventas\AiAgent\Contracts\AiToolProvider`.

### Tools currently registered (6 tools)

| Tool name | Description | Parameters |
|---|---|---|
| `financial_report` | KPIs, sales by channel, expenses by category | `start_date` (string), `end_date` (string) |
| `inventory_dead_stock` | Dead stock (products with no sales in N days) | `days` (number), `category_id` (string, optional) |
| `recent_transactions` | Latest transactions | `limit` (number, max 20) |
| `search_customers` | Search by name, email, or phone | `query` (string) |
| `search_products` | Search by name or SKU | `query` (string) |
| `export_products_excel` | Generate downloadable Excel of product catalog | *(none)* |

### Full `EzyVentasToolProvider`

```php
namespace App\AiTools;

use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Ezyventas\AiAgent\Schema\Tool;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(Authenticatable $user): array
    {
        $branchId = $user->branch_id;
        $subscriptionId = $user->branch->subscription_id;
        return [
            Tool::as('financial_report')->for('...')->withStringParameter('start_date','...')->withStringParameter('end_date','...')->using(fn(...) => ...),
            Tool::as('inventory_dead_stock')->for('...')->withNumberParameter('days','...')->withStringParameter('category_id','...')->using(fn(...) => ...),
            Tool::as('recent_transactions')->for('...')->withNumberParameter('limit','...')->using(fn(...) => ...),
            Tool::as('search_customers')->for('...')->withStringParameter('query','...')->using(fn(...) => ...),
            Tool::as('search_products')->for('...')->withStringParameter('query','...')->using(fn(...) => ...),
            Tool::as('export_products_excel')->for('...')->using(fn(...) => ...),
        ];
    }
}
```

Bound in `AppServiceProvider`:
```php
$this->app->bind(AiToolProvider::class, EzyVentasToolProvider::class);
```

### Known limitations / bugs found during testing

- **`export_products_excel` with no parameters**: caused DeepSeek 400 error because PHP `[]` encodes as JSON array, not object. Fixed via `new stdClass()` in `Tool::toProviderSchema()`.
- **`tool_use_id` vs `tool_call_id`**: internal format used Anthropic convention. Fixed to use `tool_call_id` universally; `AnthropicProvider` translates to `tool_use_id`.
- **Messages stripped of tool_call_id/tool_calls**: `DeepSeekProvider` originally filtered messages to only `role`+`content`. Fixed with `formatMessageForApi()`.
- **Signed download 403**: base64 `+/=` characters broke URL signatures. Fixed with URL-safe base64 (`strtr(..., '+/', '-_')` + `rtrim(..., '=')`).
- **401 Unauthenticated**: package routes loaded via `loadRoutesFrom` outside web middleware group. Fixed by requiring routes from `routes/web.php`.

---

## 3. Current Conversation/Message Persistence

### Migrations (3 tables)

**`ai_conversations`** (`packages/ai-agent/database/migrations/0001_create_ai_conversations_table.php`):
```php
Schema::create('ai_conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained();
    $table->string('title')->nullable();
    $table->string('provider');
    $table->string('model');
    $table->timestamps();
});
```

**`ai_messages`** (`0002_create_ai_messages_table.php`):
```php
Schema::create('ai_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['user', 'assistant', 'tool']);
    $table->longText('content')->nullable();
    $table->json('tool_calls')->nullable();
    $table->timestamps();
});
```

**`ai_tool_executions`** (`0003_create_ai_tool_executions_table.php`):
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

### Controller: end-to-end message flow

`AiChatController@sendMessage` (`packages/ai-agent/src/Http/Controllers/AiChatController.php`):

```php
public function sendMessage(SendAiMessageRequest $request, AiConversation $conversation): JsonResponse
{
    $user = Auth::user();
    $this->authorizeConversation($conversation, $user);
    set_time_limit((int) config('ai-agent.request_timeout', 60));

    $conversation->messages()->create(['role' => 'user', 'content' => $request->message]);

    if (! $conversation->title) {
        $conversation->update(['title' => mb_substr($request->message, 0, 100)]);
    }

    $assistantMessage = app(AiAgentManager::class)->ask($conversation, $request->message, $user);

    return response()->json([
        'message' => [
            'id'        => $assistantMessage->id,
            'content'   => $assistantMessage->content,
            'tool_calls'=> $assistantMessage->tool_calls,
        ],
    ]);
}
```

---

## 4. Full Service/Action Inventory

### Services (`app/Services/`)

| Class | Public methods | Description |
|---|---|---|
| `ActivityLogService` | `getFormattedActivities(Model, Request, string, bool)` | Formats Spatie activity logs with translations |
| `FinancialReportService` | `__construct(int $branchId, Carbon, Carbon)`, `generateReportData(): array` | KPIs, chart data, payment methods, sales by channel, expenses by category, bank accounts |
| `InventoryReportService` | `deadStock(int, Carbon, Carbon, ?int, int): array` | Dead stock, stock valuation, inventory aging, low stock alerts |
| `MercadoPagoService` | `buildOAuthUrl(int): string`, `exchangeCode(string): array`, `createPreference(StoreConfig, array): array` | Mercado Pago OAuth + payment preferences |
| `PaymentService` | `processPayments(Transaction, array, ?int): void` | Creates payment records, updates bank account balances |
| `PlatformMercadoPagoService` | *(N/A — not read)* | Platform-level Mercado Pago (subscription payments) |
| `PrintEncoderService` | `static encode(PrintTemplate, $dataSource, array): array` | ESC/POS + TSPL thermal printer encoding |
| `TiendaUrlService` | *(N/A)* | Online store URL generation |
| `TinifyService` | *(N/A)* | Image optimization via TinyPNG |
| `TransactionPaymentService` | `handleNewSale(array, User, ?Customer, TransactionStatus, ?CustomerBalanceMovementType): Transaction` | Orchestrates sale creation with payments and balance movements |
| `LocalImageOptimizerService` | *(N/A)* | Local image optimization via spatie/media-library |
| `Invoices/` (subfolder) | *(N/A)* | Invoice-related services |

### Actions (`app/Actions/`)

| Namespace | Classes | Description |
|---|---|---|
| `ServiceOrders\` | `CreateServiceOrderAction`, `UpdateServiceOrderAction`, `ChangeServiceOrderStatusAction` | Service order lifecycle |
| `Transactions\` | `ProcessLayawayExchange`, `ProcessProductExchange` | Layaway and product exchange workflows |
| `Invoices\` | `CreateInvoiceAction`, `CancelInvoiceAction`, `SaveBillingSettingsAction` | CFDI invoice management |
| `Fortify\` | *(Jetstream defaults)* | Password reset, 2FA, etc. |
| `Jetstream\` | *(Jetstream defaults)* | Team management, profile updates |
| `Admin\`, `Expense\`, `Product\`, `Quote\`, `Referral\`, `Service\`, `Store\`, `Subscription\`, `User\` | *(various)* | Domain-specific use cases |

---

## 5. Core Models Not Yet Covered

### Customers
- **Model**: `Customer` (`customers`), `CustomerBalanceMovement` (`customer_balance_movements`)
- **Existing query methods**: `Customer::where('branch_id', ...)`, scope via `HasSubscription` trait
- **Covered by AI tools**: Partially — `search_customers` tool exists
- **Missing**: No `getPurchaseHistory()`, `getAccountStatement()`, loyalty/purchase-frequency report

### Cash Register Sessions
- **Model**: `CashRegisterSession` (`cash_register_sessions`), `SessionCashMovement` (`session_cash_movements`)
- **Existing method**: `getCompletedPaymentTotals()` on the model, `calculateBankAccountSummary()`
- **Covered by AI tools**: No
- **Missing**: No AI tool for session summaries, discrepancies, daily close data

### Promotions/Discounts
- **Model**: `Promotion` (`promotions`), `PromotionRule`, `PromotionEffect`
- **Existing query methods**: N/A (standard Eloquent only)
- **Covered by AI tools**: No
- **Missing**: No tool to list active promotions, usage stats

### Quotes and Invoices
- **Models**: `Quote` (`quotes`), `Invoice` (`invoices`)
- **Existing query methods**: Standard Eloquent with enum casts
- **Covered by AI tools**: No
- **Missing**: No AI tool for quote status, conversion tracking

### Expenses
- **Model**: `Expense` (`expenses`), `ExpenseCategory` (`expense_categories`)
- **Existing query methods**: Standard Eloquent with `HasSubscription` and `LogsActivity`
- **Covered by AI tools**: Partially — `financial_report` tool includes expense data via `FinancialReportService`
- **Missing**: No standalone expense-by-category or expense-trend tool

### Service Orders
- **Model**: `ServiceOrder` (`service_orders`), `ServiceOrderItem`
- **Existing query methods**: `generateFolio()`, `addItemsWithStock()`, `LogsActivity`
- **Covered by AI tools**: No
- **Missing**: No AI tool for service order status, turnaround time, technician workload

### Staff/User Performance
- **Model**: `User` (`users`)
- **Existing methods**: `transactions()`, `expenses()` relationships
- **Covered by AI tools**: No
- **Missing**: No `salesByEmployee()` or `performanceByBranch()` queries exist

---

## 6. Existing Read-Only Endpoints Not Yet Services

### Dashboard (`DashboardController@__invoke`)

Direct queries in controller — NOT extracted to a Service:
```php
$todayAggregates = Transaction::where('branch_id', $branchId)
    ->whereBetween('created_at', [$startOfDay, $endOfDay])
    ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
    ->selectRaw('SUM(subtotal - total_discount + total_tax) as total_sales')
    ->selectRaw('COUNT(*) as total_count')
    ->first();

$stats['monthly_expenses'] = Expense::where('branch_id', $branchId)
    ->whereMonth('expense_date', now()->month)
    ->whereYear('expense_date', now()->year)
    ->sum('amount');
```

Also runs `getWeeklySalesTrend()`, `getLowStockProducts()`, `getActivePromotionsCount()` as private controller methods.

### Financial Report (`FinancialReportController@index`)

Uses `FinancialReportService` ✅ (already extracted), but does additional calculations in-controller (profit margins, average ticket) that could move to the Service.

### Inventory Reports (`InventoryReportController`)

Uses `InventoryReportService` ✅ (already extracted). Methods: `deadStock()`, low stock, stock valuation, inventory aging — all in the Service.

### Candidates for Service extraction + AI tools:
1. `DashboardController` → extract `SalesDashboardService` with `getTodaySales()`, `getWeeklyTrend()`, `getActivePromotions()`, `getLowStockProducts()`
2. `TransactionController@index` query logic → `TransactionQueryService` with filter/sort methods

---

## 7. Permissions Currently Wired to the AI Agent

### Permissions defined in `PermissionSeeder.php`

```php
'Agente IA' => [
    'ai_agent.access' => 'Usar el asistente de IA para hacer preguntas y consultas',
    'ai_agent.export' => 'Solicitar al asistente de IA la generación de archivos (Excel, PDF)',
],
```

### Convention check

Follows the existing `module.action` pattern: `ai_agent.access`, `ai_agent.export` ✅

### Currently enforced?

**No.** During testing, all permission checks were removed:

- `routes/ai-agent.php`: `can:ai_agent.access` removed from middleware group and individual route
- `SendAiMessageRequest::authorize()`: returns `true` unconditionally
- `AiChatDrawer.vue`: `canAccess()` function and "Sin acceso" overlay removed
- `AppLayout.vue`: `v-if` permission check on the floating trigger button removed

The permissions exist in the database but are **not enforced anywhere**. Any authenticated user can use the AI agent.

---

## 8. Known Failure Modes From Testing

### Issues encountered and fixed during testing

| # | Symptom | Root cause | Fix |
|---|---|---|---|
| 1 | `POST /ai-agent/conversations` → 401 Unauthorized | `loadRoutesFrom()` in ServiceProvider bypassed web middleware group → Sanctum stateful detection failed | Routes now loaded via `require` from `routes/web.php` |
| 2 | `POST /ai-agent/conversations` → 401 (after fix #1) | `useAiChat.js` used `import axios from 'axios'` instead of `window.axios` (no `withCredentials`) | Changed to `window.axios`, added `withCredentials: true` in `bootstrap.js` |
| 3 | 401 persisted | Missing `SANCTUM_STATEFUL_DOMAINS` for `localhost:8001` | Added to `.env` |
| 4 | 401 persisted (v3) | Missing `GET /sanctum/csrf-cookie` before first POST | Added CSRF cookie fetch in `ensureConversation()` |
| 5 | DeepSeek 400: `properties: [] is not of type "object"` | `export_products_excel` has no params → PHP `[]` encodes as JSON array | `Tool::toProviderSchema()` uses `new stdClass()` for empty properties |
| 6 | DeepSeek 400: `missing field tool_call_id` | Internal format used `tool_use_id` (Anthropic) | Changed to `tool_call_id` globally; AnthropicProvider translates |
| 7 | DeepSeek 400: `missing field tool_call_id` (v2) | `DeepSeekProvider` stripped all fields except `role`+`content` | Added `formatMessageForApi()` that preserves tool_call_id/tool_calls |
| 8 | Download link → 403 | `{path}` route param with slashes broke signed URL validation | Base64-encoded path + moved route outside auth group |
| 9 | Download link → 403 (v2) | `+/=` in base64 broke URL signatures | URL-safe base64 (`-_` instead of `+/`, padding stripped) |

### TODO comment in codebase

`AiAgentManager.php` line 36:
```php
// TODO: Append the new user message to messages list
// (handled by the caller already creating the user message row, but we
//  need to include it in the prompt — the history() method will pick it up
//  if called after the user message is persisted)
```

This is a design note — the `history()` method reads from DB so it picks up the user message automatically. The TODO can be removed.

### Pusher errors (unrelated)

`pusher-js` errors (`ERR_NAME_NOT_RESOLVED` to `sockjs-us2s.pusher.com`) were caused by a typo in `.env` (`${PUSHER_APP_CLUSTER}S` → extra S). Fixed. Not related to the AI agent.
