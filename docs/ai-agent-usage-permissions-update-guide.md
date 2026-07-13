# AI Agent Feature Updates v2 — EzyVentas 2

> **Audience:** Coding agent (DeepSeek V4 Pro in VS Code). Grounded in `docs/ai-agent-update-audit.md`. Covers 6 requirements: dynamic date/time, usage-based monthly credits, extending credits via the renewal flow, a simple usage view for subscribers, permission-aware tool filtering, and the download-link 403 bug.

---

## 0. Confirmed facts from the audit (don't re-derive)

- Prism migration succeeded (`prism-php/prism: 0.100`, `DeepSeekProvider`/custom client fully removed).
- **No token usage is captured or persisted anywhere today.**
- There's no `Plan` model — billable items are `PlanItem` (type `module` or `limit`) attached to a `SubscriptionVersion` via `SubscriptionItem`. **Reuse this exact pattern for the new AI credit limit** — don't invent a parallel system.
- Super-admin = hardcoded `$user->branch->subscription_id === 1` in `CheckSuperAdmin` middleware, not a Spatie role/permission.
- `$user->can('permission.key')` **already works** outside HTTP context and is already flowing into `EzyVentasToolProvider::tools(Authenticatable $user)` — confirmed, no plumbing needed for this part.
- 28 tools are currently registered with **zero permission filtering**.
- The download route's only protection is Laravel's `signed` middleware. **There is no cross-subscription check at all** — this was believed to exist but doesn't.

---

## 1. Dynamic date/time in the system prompt

**Root cause of the Feb 28, 2026 confusion:** confirmed in the audit — no date/time is ever injected anywhere. The model is inferring "today" from its own training data, which is why it lands on an arbitrary date.

`config('app.timezone')` is already `America/Mexico_City`, so `now()` already returns the correct local time — no timezone conversion needed, just inject it.

```php
// packages/ai-agent/src/Support/AiAgentManager.php
private function systemPrompt(Authenticatable $user): string
{
    $businessName = $user->branch?->subscription?->business_name ?? 'EzyVentas';
    $today = now()->locale('es')->translatedFormat('l, d \d\e F \d\e Y, H:i \h\r\s');

    return "Today's date and time is {$today} (America/Mexico_City). "
        . 'Always use this as "today" for any relative date calculation — "last 3 months", "this week", "yesterday" — never infer or assume a different date. '
        . "You are the reporting assistant for {$businessName}, "
        // ...(rest unchanged, see Section 4 for how the capability list becomes dynamic)
        ;
}
```

Since this method runs on every request (not cached), the date is always current — no cache invalidation to worry about.

---

## 2. Usage tracking + monthly credit limits

This single system covers requirement #2 (usage visibility + monthly limit), #3 (extending it via the renewal flow), and half of #4 (the simple subscriber-facing view, finished in Section 3).

### 2.1 Design decision: keep the subscriber-facing unit dead simple

Two different audiences need two different numbers:
- **You (super-admin)**: real cost in tokens/USD/MXN, to actually understand what this feature costs you.
- **Subscribers**: a number they can't misread as "money" and don't need to understand tokens for.

Rather than deriving a cost-weighted "credit" (which would make the counter jump unpredictably depending on how complex a question is), make **1 credit = 1 assistant response**, full stop. Track real token/cost data in parallel, purely for your own visibility — it does not feed into the limit math. This keeps the subscriber-facing number perfectly predictable ("you get 100 questions a month") while you still see real cost internally.

### 2.2 New table

```php
// packages/ai-agent/database/migrations/0004_create_ai_usage_monthly_table.php
Schema::create('ai_usage_monthly', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained();
    $table->unsignedSmallInteger('year');
    $table->unsignedTinyInteger('month');
    $table->unsignedInteger('credits_used')->default(0);       // = number of assistant responses
    $table->unsignedBigInteger('total_tokens')->default(0);     // informational, admin-only
    $table->decimal('estimated_cost_usd', 10, 4)->default(0);   // informational, admin-only
    $table->timestamps();
    $table->unique(['subscription_id', 'year', 'month']);
});
```

One row per subscription per month — cheap to read/increment on every message, no aggregation query needed at request time.

### 2.3 Capturing token usage — verify before assuming

The audit found `->text` and `->steps` used on the Prism response but wasn't fully certain whether `->usage` is exposed at the top level. **Don't guess — check it directly first:**

```php
$response = Prism::text()->using(...)->generate();
dump($response); // or Log::debug('prism response', (array) $response);
```

Prism's `Text\Response` DTO normally exposes usage cross-provider (`$response->usage->promptTokens`, `$response->usage->completionTokens`), but confirm the actual property names on the installed version (`0.100`) before writing code against them. If it's genuinely not exposed, fall back to `$response->response` (the raw provider payload) which the audit confirmed does contain `prompt_tokens`/`completion_tokens` from DeepSeek's API.

```php
// After ->generate(), regardless of which path the check above confirms:
$promptTokens = $response->usage->promptTokens ?? data_get($response->response, 'usage.prompt_tokens', 0);
$completionTokens = $response->usage->completionTokens ?? data_get($response->response, 'usage.completion_tokens', 0);
$totalTokens = $promptTokens + $completionTokens;
```

Cost estimate — add pricing to config (fill in DeepSeek's actual current per-model rate from their pricing page, these are placeholders):

```php
// config/ai-agent.php
'pricing_usd_per_million_tokens' => [
    'deepseek-v4-pro'   => ['input' => null, 'output' => null], // TODO: fill from DeepSeek pricing page
    'deepseek-v4-flash' => ['input' => null, 'output' => null],
],
```

```php
$pricing = config("ai-agent.pricing_usd_per_million_tokens.{$conversation->model}");
$costUsd = $pricing
    ? ($promptTokens / 1_000_000 * $pricing['input']) + ($completionTokens / 1_000_000 * $pricing['output'])
    : 0;
```

### 2.4 New billable item — reuses the existing renewal/upgrade flow automatically

This is the key insight for requirement #3: **don't build a separate "extend AI usage" flow.** Add one row to the existing catalog and it shows up in `ManageSubscription.vue` alongside branches/users/products, billed and versioned exactly the same way.

```php
// database/seeders/PlanItemSeeder.php — add one entry
PlanItem::firstOrCreate(['key' => 'limit_ai_credits'], [
    'type' => PlanItemType::LIMIT,
    'name' => 'Consultas de Asistente IA',
    'description' => 'Preguntas mensuales al asistente de IA',
    'monthly_price' => 15.00, // per package — set your actual price
    'is_active' => true,
    'meta' => ['quantity' => 50, 'icon' => 'pi pi-sparkles'], // 50 credits per package purchased
]);
```

**Verify before assuming this is zero frontend work:** check whether `ManageSubscription.vue` / `PlanDetailsCard.vue` renders `limit`-type `PlanItem`s generically (iterating over active items) or has each limit hardcoded individually. Given the existing pattern (branches, users, products, services, cash registers, print templates all follow the same `limit_*` naming), it's likely generic — but confirm rather than assume, since that determines whether this step is really "add one seeder row" or "add one row + one Vue block."

Add a resolver on `Subscription` matching the existing `getUserLimitData()` naming convention:

```php
// app/Models/Subscription.php
public function getAiCreditLimitData(): array
{
    $limitItem = $this->currentVersion()?->items()->where('item_key', 'limit_ai_credits')->first();
    $limit = $limitItem?->quantity ?? config('ai-agent.default_monthly_credits', 20);

    $usage = AiUsageMonthly::where('subscription_id', $this->id)
        ->where('year', now()->year)->where('month', now()->month)
        ->first();

    return [
        'limit' => $limit,
        'usage' => $usage?->credits_used ?? 0,
        'remaining' => max(0, $limit - ($usage?->credits_used ?? 0)),
    ];
}
```

`config('ai-agent.default_monthly_credits', 20)` covers every subscription that existed before this feature shipped and hasn't purchased the new item yet — set this to whatever free baseline you want to give everyone.

### 2.5 Super-admin override (requirement #2 — change the limit directly from Suscripciones > Show)

This needs to bypass the payment flow entirely — a direct admin override, not a purchase. Add to the already-super-admin-gated route group:

```php
// routes/web/super-admin.php — inside the existing CheckSuperAdmin group
Route::patch('/subscriptions/{subscription}/ai-credit-limit', [SubscriptionController::class, 'updateAiCreditLimit'])
    ->name('subscriptions.update-ai-credit-limit');
```

```php
// app/Http/Controllers/Admin/SubscriptionController.php
public function updateAiCreditLimit(Request $request, Subscription $subscription)
{
    $request->validate(['quantity' => 'required|integer|min:0']);

    $version = $subscription->currentVersion();
    $item = $version->items()->firstOrNew(['item_key' => 'limit_ai_credits']);
    $item->fill([
        'item_type' => 'limit',
        'name' => 'Consultas de Asistente IA',
        'quantity' => $request->quantity,
        'unit_price' => 0, // admin override, not a billed change
        'billing_period' => 'monthly',
    ])->save();

    return back()->with('success', 'Límite de créditos de IA actualizado.');
}
```

On `Show.vue`, add a small inline-editable field next to the other limits showing usage this month (`X / Y credits`, plus the real `estimated_cost_usd` in both USD and MXN — this view is already super-admin-only, so showing real cost here is fine and is the whole point).

### 2.6 Enforcement — check *before* calling the LLM

Blocking before the API call, not after, is what actually saves you money on over-limit usage:

```php
// AiAgentManager::ask() — first lines, before any Prism call
$subscription = $user->branch->subscription;
$limitData = $subscription->getAiCreditLimitData();

if ($limitData['remaining'] <= 0) {
    return $conversation->messages()->create([
        'role' => 'assistant',
        'content' => null, // frontend checks the flag below, not content
        'tool_calls' => ['limit_exceeded' => true, 'limit' => $limitData['limit']],
    ]);
}
```

After a successful response, increment the counters (credits always +1; tokens/cost per Section 2.3):

```php
AiUsageMonthly::firstOrCreate(['subscription_id' => $subscription->id, 'year' => now()->year, 'month' => now()->month])
    ->increment('credits_used');
// separately: ->increment('total_tokens', $totalTokens); update estimated_cost_usd
```

### 2.7 Friendly message in the Drawer

`AiChatController@sendMessage` return shape needs a flag the frontend can branch on:

```php
return response()->json([
    'message' => [
        'id' => $assistantMessage->id,
        'content' => $assistantMessage->content,
        'limit_exceeded' => $assistantMessage->tool_calls['limit_exceeded'] ?? false,
    ],
]);
```

In `useAiChat.js`, when `limit_exceeded` is true, push a special message type instead of plain content. In `AiChatDrawer.vue`, render it as a distinct card (not a normal chat bubble) with a CTA:

```vue
<div v-if="msg.limitExceeded" class="rounded-2xl px-4 py-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 text-sm">
    <p class="font-semibold text-amber-800 dark:text-amber-300 m-0 mb-1">Alcanzaste tu límite mensual</p>
    <p class="text-amber-700 dark:text-amber-400 m-0 mb-3">Has usado tus {{ msg.limit }} consultas de este mes. Puedes ampliar tu límite desde tu suscripción.</p>
    <Button label="Ampliar límite" size="small" @click="goToManageSubscription" />
</div>
```

`goToManageSubscription` just navigates to whatever route name `manage()` uses in `SubscriptionController` (confirm the exact route name before wiring this).

---

## 3. Simple usage view for subscribers (requirement #4)

Add an icon button in the `AiChatDrawer.vue` header (next to the sparkles icon) that opens a small popover — reusing the exact `ProgressBar` pattern already established in `PlanDetailsCard.vue` for visual consistency:

```vue
<Button icon="pi pi-chart-simple" text rounded size="small" @click="showUsage = true" />

<Popover v-model:visible="showUsage">
    <div class="p-3 w-56">
        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200 m-0 mb-1">Uso este mes</p>
        <p class="text-sm text-gray-600 dark:text-gray-400 m-0 mb-2">
            {{ usage.used }} de {{ usage.limit }} consultas
        </p>
        <ProgressBar :value="Math.round((usage.used / usage.limit) * 100)" :showValue="false" :pt="progressBarPt" />
    </div>
</Popover>
```

`usage` comes from a new lightweight endpoint (`GET /ai-agent/usage`) returning `{ used, limit }` from `getAiCreditLimitData()` — call it when the drawer opens, no need for real-time updates. Reuse the same `progressBarPt` object already defined in `PlanDetailsCard.vue` rather than redefining it — extract it to a shared composable/constants file if it isn't already.

No mention of tokens, dollars, or pesos anywhere in this component — exactly the simplicity requested.

---

## 4. Permission-aware tool filtering (requirement #5)

The mechanism already works (`$user->can()` confirmed functional inside `tools(Authenticatable $user)`). This is purely about wiring it in.

### 4.1 Map every tool to the permission that gates its underlying data

| Tool | Required permission |
|---|---|
| `financial_report` | `financial_reports.access` |
| `expenses_by_category`, `expense_trend` | `expenses.access` |
| `search_customers`, `customer_purchase_history` | `customers.access` |
| `customer_account_statement`, `top_customers` | `customers.see_financial_info` |
| `cash_register_session_summary`, `cash_register_discrepancies`, `daily_cash_close` | `cash_registers.sessions.access` |
| `active_promotions`, `promotion_usage_stats` | `products.manage_promos` |
| `quote_status_summary`, `quote_conversion_rate` | `quotes.access` |
| `invoice_status_summary` | `quotes.access` — **verify**: confirm whether invoices are actually part of the Cotizaciones module or a separate one not listed in the permission table; if separate, this tool needs its own permission |
| `service_order_status_summary`, `service_order_workload`, `service_order_turnaround` | `services.orders.access` |
| `sales_by_employee`, `ranking_by_branch` | `financial_reports.access` |
| `today_sales_summary`, `weekly_sales_trend` | `dashboard.see_sales` |
| `low_stock_products`, `search_products` | `products.access` |
| `recent_transactions`, `search_transactions` | `transactions.access` |
| `export_products_excel` | `products.access` **and** `products.import_export` |
| `inventory_dead_stock` | `products.access` |

### 4.2 Implementation

Restructure `EzyVentasToolProvider::tools()` so each tool carries its permission requirement, then filter:

```php
public function tools(Authenticatable $user): array
{
    return collect($this->definitions($user))
        ->filter(fn ($def) => $def['permission'] === null || $user->can($def['permission']))
        ->pluck('tool')
        ->values()
        ->all();
}

private function definitions(Authenticatable $user): array
{
    $branchId = $user->branch_id;
    $subscriptionId = $user->branch->subscription_id;

    return [
        [
            'permission' => 'financial_reports.access',
            'tool' => (new Tool)->as('financial_report')->for('...')->using(fn (...) => ...),
        ],
        // ... one entry per tool, per the table above; tools with no gate (rare — maybe none) use 'permission' => null
    ];
}
```

This is a mechanical refactor of the existing 28 tool definitions — wrap each existing closure in this array shape, don't rewrite the closures themselves.

### 4.3 Make the system prompt's capability list match the filtered set

Right now the "you can answer questions about X, Y, Z" sentence is a static string — after filtering, a user without `financial_reports.access` would still be told the agent can discuss financial reports, then get a confusing "I don't have a tool for that." Build it dynamically instead:

```php
private function systemPrompt(Authenticatable $user): string
{
    $tools = app(AiToolProvider::class)->tools($user);
    $categories = collect($tools)->map(fn ($t) => $t->category ?? null)->filter()->unique()->implode(', ');
    // ...
    return "... You can answer questions about: {$categories}. If asked about something outside these topics, say you don't have access to that information rather than guessing.";
}
```

This needs each `Tool` definition to carry a `category` (Sales, Customers, Cash Register, etc. — matching the grouping already used in the audit's tool table) — add that as metadata on the definition array in Section 4.2 alongside `permission`, not on the Prism `Tool` object itself if Prism's builder doesn't support arbitrary metadata (check).

---

## 5. Download link 403 — diagnosis and fix

### 5.1 What's actually happening

The audit found **no `abort(403)` anywhere in this flow** — the only 403-capable code is Laravel's own `signed` middleware (`Illuminate\Routing\Middleware\ValidateSignature`), which renders its own 403 error page when the signature is invalid, **before the controller method ever runs**. The `abort(401)` inside `download()` for `hasValidSignature()` is dead code — it can never be reached, because the middleware already stops an invalid request first. That's very likely your 403: **the signed URL itself is failing validation**, not a business-logic check.

### 5.2 Most likely root cause: URL generation mismatch

Signed URLs are computed from the full URL Laravel *thinks* it's generating — host, scheme, and path all matter. On shared hosting, the most common cause of "every signed URL is invalid immediately" is a mismatch between how the URL was generated and how it's actually accessed. Check, in this order:

1. **`APP_URL` in `.env`** — must exactly match the real production URL including `https://`, with no trailing slash. If it's `http://` or points to a different host than what Hostinger actually serves, every signed URL will fail its check the moment it's requested.
2. **Reverse proxy / SSL termination**: if Hostinger terminates SSL before the request reaches PHP (common on shared hosting), Laravel might think the request came in over `http` even though the browser used `https`, again causing a scheme mismatch, unless `TrustProxies` is configured to trust the proxy headers.
3. **Confirm it's not just TTL expiration**: test by generating a fresh download link and clicking it *immediately* (well within the 15-minute window). If it still 403s right away, that confirms it's (1) or (2), not expiration.

### 5.3 Fix

```php
// .env — verify this matches your real production URL exactly
APP_URL=https://your-real-production-domain.com
```

```php
// app/Providers/AppServiceProvider.php — boot(), if behind a proxy that terminates SSL
if ($this->app->environment('production')) {
    URL::forceScheme('https');
}
```

```php
// bootstrap/app.php or app/Http/Middleware/TrustProxies.php, if not already configured
->trustProxies(at: '*') // or the specific Hostinger proxy IP if known — '*' is simplest for shared hosting
```

Clear caches after changing `.env`:

```bash
php artisan config:clear
```

### 5.4 Also add the cross-subscription check that was believed to exist but doesn't

Independent of the 403 bug — this is a real gap, not a false alarm. Add it now:

```php
// packages/ai-agent/src/Http/Controllers/AiChatController.php
public function download(Request $request, string $path)
{
    // The 'signed' middleware already validated the signature by this point.

    $decodedPath = base64_decode(strtr($path, '-_', '+/'));
    if (! $decodedPath || ! str_contains($decodedPath, '/')) {
        abort(400, 'Invalid file path.');
    }

    // Cross-subscription check — extract the subscription segment and compare to the requester
    $pathSubscriptionId = (int) explode('/', $decodedPath)[1] ?? null; // 'exports/{subscriptionId}/file.xlsx'
    if ($pathSubscriptionId !== $request->user()->branch->subscription_id) {
        abort(403, 'No tienes acceso a este archivo.');
    }

    $disk = Storage::disk(config('ai-agent.export_disk', 'local'));
    if (! $disk->exists($decodedPath)) {
        abort(404);
    }

    return response()->download($disk->path($decodedPath));
}
```

Note this route needs `auth` middleware added (check whether it currently has one — the audit only showed `signed`) since `$request->user()` must be available to compare subscriptions. A signed URL alone authenticates the *link*, not the *person clicking it* — both checks are needed together.

---

## 6. Implementation order

1. Section 1 (date/time) — trivial, ship first.
2. Section 5 (403 fix + missing cross-subscription check) — security-relevant, do before adding more export tools.
3. Section 2.2–2.3 (usage table + token capture, verify the Prism response shape first).
4. Section 2.4–2.5 (PlanItem + super-admin override) — confirm the `ManageSubscription.vue` generic-rendering assumption before estimating this as "just a seeder row."
5. Section 2.6–2.7 (enforcement + Drawer messaging).
6. Section 3 (usage popover for subscribers).
7. Section 4 (permission-aware tools) — do this last since it touches all 28 existing tool definitions; easiest to do once everything else is stable.
