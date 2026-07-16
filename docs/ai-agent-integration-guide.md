# AI Agent Integration Guide — EzyVentas 2 (→ reusable package)

> **Audience:** This document is written to be handed directly to a coding agent (e.g. DeepSeek V4 Pro in VS Code) as an implementation spec. It assumes the agent has read `docs/project-structure.md` (the codebase audit) first. Every recommendation below is grounded in that audit — not generic best practices — so follow the exact conventions referenced.

---

## 0. Confirmed project facts (from audit — do not re-derive, just use)

- Laravel **12.21.0**, PHP **8.2**, Inertia v2 + Vue 3.3 + PrimeVue 4, no Pinia.
- Multi-tenancy: **no package**. Manual scoping via `subscription_id` on `Subscription` (root tenant) → `Branch` → most models via `branch_id`. Trait: `App\Traits\HasSubscription` (`getSubscriptionId()`, `scopeBySubscription()`). Middleware: `EnsureSubscriptionScope` (global, web group).
- Business logic layers: `app/Actions/{Module}/*Action.php` (single use-case, `execute()` method) and `app/Services/*Service.php` (reusable logic, e.g. `FinancialReportService`, `InventoryReportService`). No DTOs, no Repositories, no Policies (authorization is Spatie `can:` middleware in controllers).
- Export: `maatwebsite/excel` only. **No PDF library installed.** Existing exports in `app/Exports/*Export.php`, currently used with `Excel::download()` (streamed, not persisted).
- Queue: `database` driver, no Horizon. Broadcasting: **Pusher configured, Reverb supported as alternative**, frontend already has `laravel-echo` + `pusher-js` wired — kept available for a later phase, but **Phase 1 of the AI agent does not use it.** The chat response is delivered synchronously in the HTTP response and revealed on the frontend with a fade/soft transition instead of token-by-token streaming (see Section 7). This avoids depending on a persistent queue worker, which the current shared hosting plan can't reliably run.
- Auth: Jetstream (Inertia stack) + Sanctum guard. Permissions: `spatie/laravel-permission` with a custom `Role` model, checked via `HasMiddleware` in controllers (`can:module.action`, e.g. `services.orders.access`).
- Settings: a **polymorphic settings system already exists** — `SettingDefinition` (key, level, type, default) + `SettingValue` (`settable_type`/`settable_id` morph). This is the correct place to store per-tenant AI configuration — do not add new ad-hoc columns.
- Known gap to fix, not repeat: `StoreConfig.mp_access_token` is hidden from JSON but **not encrypted at rest**. AI provider API keys must use Laravel's `encrypted` cast.
- No private Composer packages exist yet; no vendor namespace is established beyond `App\`.

---

## 1. Non-negotiable architectural principles

1. **The LLM never touches the database directly.** No raw SQL tool, no generic "query builder" tool. Every capability is an explicit, named tool that wraps an existing (or new) Service/Action.
2. **Tenant scoping is injected by PHP code, never supplied by the model.** Every tool closure resolves `subscription_id`/`branch_id` from `Auth::user()` server-side, exactly like existing controllers do (`$user->branch_id`). The model only supplies business parameters (date ranges, product names, etc.).
3. **Model-agnostic by config, not by code branching.** Provider + model string live in tenant settings (via the existing `SettingValue` system), resolved once per request into a Prism call. No `if ($provider === 'openai')` anywhere in application code.
4. **Reuse, don't duplicate.** `FinancialReportService`, `InventoryReportService`, and the `app/Exports/*` classes are wrapped, not rewritten.
5. **Every tool call is audited.** Tool name, arguments, tenant, user, and duration are logged (new `ai_tool_executions` table), consistent with the project's existing use of `spatie/laravel-activitylog`.
6. **Destructive/write actions require explicit confirmation.** Phase 1 of this integration is **read-only + file export only**. Do not expose any tool that creates, updates, or deletes business records until a confirmation-flow pattern is explicitly designed and reviewed.

---

## 2. Package skeleton

Build this as a standalone Composer package under `packages/ai-agent/` in the EzyVentas repo first (path repository), then extract to its own Git repo once validated. Replace `Vendor` below with your actual namespace (e.g. `Ezyventas`, or a neutral one you'll reuse across client ERPs, e.g. `AgentKit`).

```
packages/ai-agent/
├── composer.json
├── config/
│   └── ai-agent.php
├── database/
│   └── migrations/
│       ├── 0001_create_ai_conversations_table.php
│       ├── 0002_create_ai_messages_table.php
│       └── 0003_create_ai_tool_executions_table.php
├── routes/
│   └── ai-agent.php
├── resources/
│   └── js/
│       ├── composables/useAiChat.js
│       └── components/AiChatDrawer.vue
└── src/
    ├── AiAgentServiceProvider.php
    ├── Contracts/
    │   └── AiToolProvider.php
    ├── Support/
    │   ├── AiAgentManager.php
    │   └── ToolRegistry.php
    ├── Models/
    │   ├── AiConversation.php
    │   ├── AiMessage.php
    │   └── AiToolExecution.php
    └── Http/
        ├── Controllers/AiChatController.php
        └── Requests/SendAiMessageRequest.php
```

> `Events/AiMessageChunkBroadcast.php` and `Jobs/ProcessAiChatMessage.php` are **not part of Phase 1.** They belong to the optional future streaming phase described at the end of Section 7 — don't scaffold them yet.

### `packages/ai-agent/composer.json`

```json
{
    "name": "vendor/ai-agent",
    "description": "Model-agnostic AI reporting/export agent for multi-tenant Laravel apps",
    "type": "library",
    "require": {
        "php": "^8.2",
        "illuminate/support": "^11.0|^12.0",
        "prism-php/prism": "^0.x"
    },
    "autoload": {
        "psr-4": { "Vendor\\AiAgent\\": "src/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Vendor\\AiAgent\\AiAgentServiceProvider"]
        }
    }
}
```

Register in the host app's root `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "packages/ai-agent" }
],
"require": {
    "vendor/ai-agent": "*"
}
```

`^11.0|^12.0` on `illuminate/support` is what makes the package installable on both your Laravel 11 and Laravel 12 projects without a fork.

---

## 3. Database schema

Follow the project's existing tenancy convention exactly: scope by `subscription_id` (not `branch_id`), since a conversation belongs to the account, not a single branch.

```php
// 0001_create_ai_conversations_table.php
Schema::create('ai_conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained();
    $table->string('title')->nullable(); // auto-generated from first message
    $table->string('provider'); // e.g. "anthropic", resolved at creation time
    $table->string('model');
    $table->timestamps();
});

// 0002_create_ai_messages_table.php
Schema::create('ai_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['user', 'assistant', 'tool']);
    $table->longText('content')->nullable();
    $table->json('tool_calls')->nullable(); // raw tool_use blocks, for debugging
    $table->timestamps();
});

// 0003_create_ai_tool_executions_table.php
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

---

## 4. Tenant-scoped AI settings (reuse the existing settings system)

Do **not** add new columns to `Subscription` or `Branch`. Register new `SettingDefinition` rows instead — this is exactly what that system is for:

```php
// database/seeders/AiAgentSettingsSeeder.php
SettingDefinition::firstOrCreate(['key' => 'ai.provider'], [
    'name' => 'AI Provider', 'module' => 'ai_agent', 'level' => 'subscription',
    'type' => 'string', 'default_value' => 'anthropic',
]);
SettingDefinition::firstOrCreate(['key' => 'ai.model'], [
    'name' => 'AI Model', 'module' => 'ai_agent', 'level' => 'subscription',
    'type' => 'string', 'default_value' => 'claude-sonnet-5',
]);
SettingDefinition::firstOrCreate(['key' => 'ai.api_key'], [
    'name' => 'AI Provider API Key', 'module' => 'ai_agent', 'level' => 'subscription',
    'type' => 'encrypted_string', 'default_value' => null,
]);
```

Add an `encrypted_string` type handling in `SettingValue` (or a cast on read) so `ai.api_key` is stored via Laravel's `Crypt::encryptString()` — this closes the gap flagged in the audit for `mp_access_token`, rather than repeating it.

Resolving config per request:

```php
$subscription = $user->branch->subscription;
$provider = $subscription->settings()->forKey('ai.provider')->value ?? config('ai-agent.default_provider');
$model    = $subscription->settings()->forKey('ai.model')->value ?? config('ai-agent.default_model');
$apiKey   = decrypt($subscription->settings()->forKey('ai.api_key')->value ?? null) ?? config('ai-agent.default_api_key');
```

(Add a `forKey()` scope to `SettingValue` if it doesn't already exist — trivial `where('setting_definition_id', ...)` via a join to `SettingDefinition.key`.)

This design also lets you sell the **same package** to your ERP clients: each client subscription can plug in their own provider/API key, or you can default everyone to a key you manage and meter usage per plan.

---

## 5. Core orchestration (`AiAgentManager`)

```php
namespace Vendor\AiAgent\Support;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

class AiAgentManager
{
    public function __construct(private ToolRegistry $tools) {}

    public function ask(AiConversation $conversation, string $userMessage, User $user): AiMessage
    {
        $tools = $this->tools->forUser($user); // tenant-aware tool set, see below

        $response = Prism::text()
            ->using(Provider::from($conversation->provider), $conversation->model)
            ->withSystemPrompt($this->systemPrompt($user))
            ->withMessages($this->history($conversation))
            ->withTools($tools)
            ->withMaxSteps(6) // hard cap — prevents runaway tool loops
            ->generate();

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response->text,
            'tool_calls' => $response->steps ?? null,
        ]);
    }

    private function systemPrompt(User $user): string
    {
        return "You are the reporting assistant for {$user->branch->subscription->business_name}, "
            . "a point-of-sale business. Answer only using tool results. "
            . "If a question requires data you don't have a tool for, say so — never invent numbers. "
            . "Respond in the same language the user writes in.";
    }
}
```

### `ToolRegistry` and the `AiToolProvider` contract

The package defines the contract; **the host app (EzyVentas) implements it** with its own domain tools. This is what keeps the package portable to other ERPs — the package never imports `App\Services\FinancialReportService` directly.

```php
// packages/ai-agent/src/Contracts/AiToolProvider.php
namespace Vendor\AiAgent\Contracts;

interface AiToolProvider
{
    /** @return \Prism\Prism\Tool[] */
    public function tools(User $user): array;
}
```

```php
// app/AiTools/EzyVentasToolProvider.php  (lives in the HOST app, not the package)
namespace App\AiTools;

use Vendor\AiAgent\Contracts\AiToolProvider;
use Prism\Prism\Tool;
use App\Services\FinancialReportService;
use App\Services\InventoryReportService;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(User $user): array
    {
        $branchId = $user->branch_id;

        return [
            Tool::as('financial_report')
                ->for('Get KPIs, sales by channel, expenses by category for a date range')
                ->withStringParameter('start_date', 'YYYY-MM-DD')
                ->withStringParameter('end_date', 'YYYY-MM-DD')
                ->using(function (string $start_date, string $end_date) use ($branchId) {
                    $service = new FinancialReportService(
                        $branchId, Carbon::parse($start_date), Carbon::parse($end_date)
                    );
                    return json_encode($service->generateReportData());
                }),

            Tool::as('inventory_dead_stock')
                ->for('List products with no sales movement in the last N days')
                ->withNumberParameter('days', 'Number of days without movement')
                ->using(fn (int $days) => json_encode(
                    app(InventoryReportService::class)->deadStock($branchId, $days)
                )),

            // ...register export tools here (see Section 6)
        ];
    }
}
```

Bind it in `AppServiceProvider` (host app):

```php
$this->app->bind(AiToolProvider::class, EzyVentasToolProvider::class);
```

`ToolRegistry::forUser()` in the package simply resolves `AiToolProvider` from the container and calls `->tools($user)` — this is the entire seam between package and app.

---

## 6. File export tools (PDF / Excel / txt)

### Adapt existing Excel exports (change `download` → `store` + signed URL)

Current pattern streams directly to the browser (`Excel::download`). For an AI tool, you need a **file path the model can hand back as a link**, so switch to `Excel::store()`:

```php
Tool::as('export_products_excel')
    ->for('Generate a downloadable Excel file of the current product catalog')
    ->using(function () use ($subscriptionId, $user) {
        $filename = 'exports/' . $subscriptionId . '/products_' . now()->timestamp . '.xlsx';
        Excel::store(new ProductsExport, $filename, 'local');

        $url = URL::temporarySignedRoute(
            'ai-agent.download', now()->addMinutes(15), ['path' => $filename]
        );

        return json_encode(['download_url' => $url, 'expires_in_minutes' => 15]);
    });
```

Add a small signed-route controller in the package (`AiChatController@download`) that validates the signature **and** re-checks that the requesting user's `subscription_id` matches the one encoded in the path — never trust the path alone.

### Add a PDF library (none exists yet)

For Blade-based PDFs (reports, statements) that match your existing view conventions, install `barryvdh/laravel-dompdf`:

```bash
composer require barryvdh/laravel-dompdf
```

```php
Tool::as('export_financial_report_pdf')
    ->for('Generate a PDF summary of the financial report for a date range')
    ->withStringParameter('start_date', 'YYYY-MM-DD')
    ->withStringParameter('end_date', 'YYYY-MM-DD')
    ->using(function (string $start_date, string $end_date) use ($branchId, $subscriptionId) {
        $data = (new FinancialReportService($branchId, Carbon::parse($start_date), Carbon::parse($end_date)))
            ->generateReportData();

        $pdf = Pdf::loadView('ai-exports.financial-report', $data);
        $filename = "exports/{$subscriptionId}/financial_" . now()->timestamp . '.pdf';
        Storage::disk('local')->put($filename, $pdf->output());

        $url = URL::temporarySignedRoute('ai-agent.download', now()->addMinutes(15), ['path' => $filename]);
        return json_encode(['download_url' => $url]);
    });
```

Only reach for `spatie/browsershot` instead if you later need pixel-perfect CSS/print layouts (it needs Node + headless Chrome — more infra, skip it for v1).

### `.txt` exports

No package needed — a plain `Storage::disk('local')->put($filename, $content)` behind the same signed-URL pattern is sufficient.

---

## 7. Response delivery — Phase 1: synchronous request + fade-in reveal (no streaming)

**Decision:** Phase 1 does not do ChatGPT-style token-by-token streaming. The user sees a loader while the agent works, then the complete answer appears with a soft fade/transition. This removes the dependency on a persistent queue worker and on Pusher entirely for the core chat flow — both of which are awkward on the current shared hosting plan.

### Backend: plain synchronous call

`AiChatController@sendMessage` calls `AiAgentManager::ask()` directly (the method from Section 5 already uses `->generate()`, not a stream) and returns the finished message in the HTTP response:

```php
// Vendor\AiAgent\Http\Controllers\AiChatController
public function sendMessage(SendAiMessageRequest $request, AiConversation $conversation)
{
    $this->authorizeConversation($conversation, $request->user()); // subscription check

    $conversation->messages()->create(['role' => 'user', 'content' => $request->message]);

    $assistantMessage = app(AiAgentManager::class)->ask(
        $conversation, $request->message, $request->user()
    );

    return response()->json([
        'message' => [
            'id' => $assistantMessage->id,
            'content' => $assistantMessage->content,
        ],
    ]);
}
```

No job, no event, no channel authorization needed for this flow. The request just takes a few seconds — bump `max_execution_time` for this route only (via `.user.ini` in that route's context, or `set_time_limit(60)` at the top of the method) so Hostinger doesn't cut it off mid-request.

### Frontend: loader → fade-in reveal

`useAiChat.js` composable:

```js
import { ref } from 'vue';

export function useAiChat(conversationId) {
    const messages = ref([]);
    const isThinking = ref(false);

    async function sendMessage(text) {
        messages.value.push({ role: 'user', content: text, visible: true });
        isThinking.value = true;

        try {
            const { data } = await axios.post(
                `/ai-agent/conversations/${conversationId}/messages`,
                { message: text }
            );
            // pushed with visible:false first so the <Transition> below can animate it in
            messages.value.push({ role: 'assistant', content: data.message.content, visible: false });
            await nextTick();
            messages.value.at(-1).visible = true;
        } catch (e) {
            // surface via PrimeVue toast, see Section 9
        } finally {
            isThinking.value = false;
        }
    }

    return { messages, isThinking, sendMessage };
}
```

`AiChatDrawer.vue` (relevant excerpt — PrimeVue `ProgressSpinner` or a simple 3-dot loader while `isThinking`, then a native Vue `<Transition>` for the fade):

```vue
<div v-for="msg in messages" :key="msg.id ?? msg.content">
    <Transition name="fade-in">
        <div v-if="msg.visible" class="chat-bubble">{{ msg.content }}</div>
    </Transition>
</div>
<ProgressSpinner v-if="isThinking" style="width: 24px; height: 24px" />
```

```css
.fade-in-enter-active { transition: opacity 0.4s ease, transform 0.4s ease; }
.fade-in-enter-from { opacity: 0; transform: translateY(6px); }
```

This is Vue's built-in `<Transition>` component — no extra dependency needed, and it matches the "elegant" feel you're after without the complexity of a WebSocket layer.

### Future enhancement (optional, later phase — not now)

If you later want real token-by-token streaming, the path is: dispatch a queued job that calls Prism's streaming API and broadcasts chunks over a private Pusher channel (`private-subscription.{subscriptionId}.ai-chat.{conversationId}`), with channel authorization in `routes/channels.php` checking `$user->branch->subscription_id === $subscriptionId`. Don't build this until you've moved off shared hosting and actually want the incremental UX — it adds a job, an event class, and a channel-auth surface for no benefit at current scale.

---

## 8. HTTP layer

```php
// packages/ai-agent/routes/ai-agent.php
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'can:ai_agent.access'])
    ->prefix('ai-agent')->as('ai-agent.')->group(function () {
        Route::post('/conversations', [AiChatController::class, 'store'])->name('conversations.store');
        Route::post('/conversations/{conversation}/messages', [AiChatController::class, 'sendMessage'])
            ->name('messages.store')->middleware('can:ai_agent.access');
        Route::get('/download/{path}', [AiChatController::class, 'download'])
            ->name('download')->middleware('signed');
    });
```

That's the complete route list for Phase 1 — `sendMessage` is a normal blocking request (Section 7), so no broadcasting route or channel registration is needed yet.

Add permissions (matching the existing `module.action` / `module.action_verb` convention seen in `services.orders.access`, `pos.create_sale`):

- `ai_agent.access` — can open the chat and ask questions
- `ai_agent.export` — can request file generation (separate from asking questions, so you can gate it by plan tier)

---

## 9. Frontend (PrimeVue, matching existing patterns)

Follow the same patterns as `PaymentModal.vue` / `AppLayout.vue` (PrimeVue `:pt` for Tailwind, toasts via the already-registered global `<Toast />`).

```
resources/js/
├── Components/AiChatDrawer.vue     # PrimeVue Drawer/Sidebar component
└── composables/useAiChat.js        # Echo subscription + message state
```

`useAiChat.js` (full implementation in Section 7) should:
- POST to `/ai-agent/conversations/{id}/messages` and await the full JSON response — no Echo subscription needed in Phase 1
- Track an `isThinking` flag to drive the loader (PrimeVue `ProgressSpinner` or a 3-dot indicator)
- Reveal the finished message via Vue's `<Transition name="fade-in">` rather than appending partial chunks
- Surface tool-execution or timeout errors as PrimeVue toasts (reuse `useToast()`, already used app-wide)

Since there's no Pinia, keep chat state local to the composable/component (consistent with the rest of the app's state approach) — a chat drawer doesn't need global state.

---

## 10. Phased implementation checklist

- [ ] **Phase 1 — Package skeleton in EzyVentas.** Create `packages/ai-agent/`, `composer require prism-php/prism`, wire the path repository, confirm `php artisan about` shows the package registered.
- [ ] **Phase 2 — Migrations + models.** Run the 3 migrations. Confirm `ai_conversations.subscription_id` scoping works with a manual tinker test.
- [ ] **Phase 3 — Settings.** Seed the 3 `SettingDefinition` rows, implement `encrypted_string` handling, manually set a test API key for one subscription.
- [ ] **Phase 4 — First tool, no UI.** Implement `EzyVentasToolProvider` with just `financial_report`. Test end-to-end via `php artisan tinker` calling `AiAgentManager::ask()` directly — no HTTP yet.
- [ ] **Phase 5 — Export tools.** Add `export_products_excel` + `export_financial_report_pdf` (install dompdf). Confirm signed download URLs work and expire correctly.
- [ ] **Phase 6 — HTTP layer (synchronous).** Add the routes from Section 8. `sendMessage` calls `AiAgentManager::ask()` directly and returns the finished message — no queue, no broadcasting, no Pusher involved.
- [ ] **Phase 7 — Frontend.** Build `AiChatDrawer.vue` + `useAiChat.js` per Section 7: loader while `isThinking`, then a `<Transition name="fade-in">` reveal of the complete answer. Add the drawer trigger to `AppLayout.vue`.
- [ ] **Phase 8 — Permissions + plan gating.** Add `ai_agent.access` / `ai_agent.export` permissions, wire into your subscription plan/feature matrix (however plan features are currently gated — check `CheckSubscriptionStatus` middleware for the existing pattern).
- [ ] **Phase 9 — Extract to standalone repo.** Once stable in EzyVentas, move `packages/ai-agent` to its own Git repository, tag a version, and switch the path repository to a VCS repository in EzyVentas' `composer.json`.
- [ ] **Phase 10 — Port to a client ERP.** Install the same package via VCS repository in the second project, write that project's own `XyzToolProvider`, confirm it works unmodified on Laravel 11.
- [ ] **Phase 11 (optional, future — not now).** Only if you move to a VPS and want real token-by-token streaming: add the queued job, `AiMessageChunkBroadcast` event, and Pusher channel authorization described at the end of Section 7.

---

## 11. Security checklist (re-verify before shipping Phase 6)

- [ ] Every tool closure derives `subscription_id`/`branch_id` from `Auth::user()`, never from a tool parameter.
- [ ] `ai.api_key` setting values are encrypted at rest (`Crypt::encryptString`), not stored plain like `mp_access_token`.
- [ ] Download routes are signed **and** re-validate that the path's embedded subscription ID matches the requesting user.
- [ ] `withMaxSteps()` is set on every `Prism::text()` call to bound cost and prevent tool-loop runaway.
- [ ] No tool in Phase 1–8 performs a write/delete against business data — read + export only.
- [ ] `ai_tool_executions` logs every call (tool name, args, subscription, user, duration) for audit and cost analysis.
- [ ] Rate limiting on `/ai-agent/conversations/{id}/messages`, scoped per subscription (Laravel's `throttle` middleware keyed by subscription ID, not just IP).

---

## 12. Portability notes for other Laravel 11/12 projects (e.g. Element Plus ERPs)

- The package's `src/` code has zero dependency on PrimeVue, Jetstream, or `subscription_id` naming — those are host-app concerns. The only cross-package contract is `AiToolProvider`.
- If a client ERP uses a different tenancy column name (e.g. `company_id` instead of `subscription_id`), that's fine — `ai_conversations`/`ai_messages` in the package schema use their own `subscription_id` FK pointing at whatever the host app's tenant model is; alias it via a migration customization if needed, or make the FK column name configurable in `config/ai-agent.php`.
- For an Element Plus frontend, only `resources/js/components/AiChatDrawer.vue` needs a rewrite; `useAiChat.js` (the composable with Echo + fetch logic) is framework-UI-agnostic and can be copied as-is.
- Laravel 11 vs 12: no breaking changes expected for this package's dependencies (`illuminate/support ^11.0|^12.0`, Prism supports both). Verify `spatie/laravel-permission` major version matches the target project before requiring it as a suggested dependency.
