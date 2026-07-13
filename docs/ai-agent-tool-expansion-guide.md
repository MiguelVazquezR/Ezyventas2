# AI Agent Tool Expansion Guide — EzyVentas 2

> **Audience:** Coding agent (DeepSeek V4 Pro in VS Code). This builds directly on `docs/ai-agent-current-state.md` — the agent already has a working `AiProvider`/`EzyVentasToolProvider` architecture with 6 tools. This document adds ~18 more read-only tools, grouped by domain, reusing existing Services wherever possible and specifying new Service methods where none exist yet.

---

## 0. Fix / evaluate before adding new tools

> **Permissions note:** `ai_agent.access` / `ai_agent.export` are **intentionally not enforced**. The agent is available to all authenticated users for now — this is a deliberate product decision, not an oversight. Do not re-add `can:ai_agent.*` middleware to the routes, `SendAiMessageRequest::authorize()`, or the Vue components. If this changes later, that's a separate task — don't reintroduce it while working on the items below.

1. **Wire up `ai_tool_executions` logging.** In `AiAgentManager`'s tool-execution loop (the `while ($step < $maxSteps)` block), wrap each `$tool->execute($toolCall['arguments'])` call to record a row: `tool_name`, `arguments`, `result` (truncate if large), `subscription_id`, `user_id`, `duration_ms`. You already have the table — just insert into it. This becomes essential once there are 24+ tools instead of 6, for debugging which one DeepSeek picked and what it got back.

2. **Evaluate migrating to Prism PHP** (recommended — see Section 6 below for the full task). Do this before adding the 21 new tools if it succeeds, since it's cheaper to migrate 6 tools now than 27 later.

3. **Update the DeepSeek model identifier** — independent of the Prism decision, see the note at the end of Section 6. This is time-sensitive.

Do these first. Then proceed to the tool catalog below.

---

## 1. New Service methods needed (grouped by domain)

Create one new Service class per domain below (matching the existing `app/Services/*Service.php` convention). Where a method already exists on a model, reuse it — don't duplicate.

### `CustomerReportService` (new)
- `getPurchaseHistory(int $customerId, int $limit = 20): array` — recent transactions for one customer, joined with `TransactionItem`
- `getAccountStatement(int $customerId): array` — wraps `CustomerBalanceMovement` query, running balance
- `getTopCustomers(int $branchId, Carbon $start, Carbon $end, int $limit = 10): array` — by total spend, using existing `Transaction`/`Customer` relationship

### `CashRegisterReportService` (new)
- `getSessionSummary(int $sessionId): array` — wraps existing `CashRegisterSession::getCompletedPaymentTotals()` + `calculateBankAccountSummary()`, no new query logic needed
- `getDiscrepancies(int $branchId, Carbon $start, Carbon $end): array` — sessions where counted cash ≠ expected cash (new query, compare `SessionCashMovement` sums against closing amount)
- `getDailyClose(int $branchId, string $date): array` — one day's session(s), reusing the summary method above

### `PromotionReportService` (new)
- `getActivePromotions(int $branchId): array` — `Promotion::where('branch_id', ...)->whereActive()` (new scope if it doesn't exist) joined with `PromotionRule`
- `getUsageStats(int $promotionId, Carbon $start, Carbon $end): array` — count of transactions where the promotion was applied (check how `PromotionEffect` links to `TransactionItem`/`Transaction` — confirm this FK exists before writing the query; audit didn't confirm it)

### `QuoteInvoiceReportService` (new)
- `getQuoteStatusSummary(int $branchId, Carbon $start, Carbon $end): array` — counts by `Quote` status enum
- `getConversionRate(int $branchId, Carbon $start, Carbon $end): array` — quotes converted to sales ÷ total quotes (check whether `Quote` has a `converted_transaction_id` or similar link — confirm in code before assuming)
- `getInvoiceStatusSummary(int $branchId, Carbon $start, Carbon $end): array` — CFDI invoices issued/cancelled counts, reusing enum casts already on `Invoice`

### `ExpenseReportService` (new — or extend `FinancialReportService`)
- `byCategory(int $branchId, Carbon $start, Carbon $end): array` — group by `ExpenseCategory`
- `trend(int $branchId, int $months = 6): array` — monthly totals for the last N months

### `ServiceOrderReportService` (new)
- `getStatusSummary(int $branchId): array` — counts grouped by status
- `getWorkloadByTechnician(int $branchId, Carbon $start, Carbon $end): array` — count/avg turnaround per assigned user
- `getAverageTurnaroundTime(int $branchId, Carbon $start, Carbon $end): array` — time between creation and `ChangeServiceOrderStatusAction` marking "completed" (check `ServiceOrder` for a `completed_at` column or derive from activity log)

### `StaffPerformanceService` (new)
- `salesByEmployee(int $branchId, Carbon $start, Carbon $end): array` — group `Transaction` by `user_id`
- `rankingByBranch(int $subscriptionId, Carbon $start, Carbon $end): array` — only relevant if a subscription has multiple branches

### `SalesDashboardService` (extraction — audit already flagged this)
Move the logic currently inline in `DashboardController@__invoke`:
- `getTodaySales(int $branchId): array`
- `getWeeklyTrend(int $branchId): array`
- `getActivePromotionsCount(int $branchId): int`
- `getLowStockProducts(int $branchId, int $threshold = 5): array`

### `TransactionQueryService` (extraction/generalization of `recent_transactions`)
- `search(int $branchId, array $filters): array` — filters: `status`, `payment_method`, `date_from`, `date_to`, `channel`. This replaces the narrow `recent_transactions` tool with something that can answer "sales paid by card last week" style questions.

---

## 2. New tools to register in `EzyVentasToolProvider`

All tools follow the exact pattern already in place: `Tool::as(...)->for(...)->withXParameter(...)->using(fn (...) use ($branchId, $subscriptionId) => ...)`. Every closure resolves scope from the already-captured `$branchId`/`$subscriptionId` — never from a tool parameter, per the non-negotiable principle already established.

| Tool name | Description | Parameters | Backs onto |
|---|---|---|---|
| `customer_purchase_history` | Get a customer's recent purchase history | `customer_query` (string, name/email/phone) | `CustomerReportService::getPurchaseHistory` |
| `customer_account_statement` | Get a customer's balance/credit statement | `customer_query` (string) | `CustomerReportService::getAccountStatement` |
| `top_customers` | Rank customers by spend in a period | `start_date`, `end_date`, `limit` (optional, default 10) | `CustomerReportService::getTopCustomers` |
| `cash_register_session_summary` | Summarize one cash register session | `session_id` (number) | `CashRegisterReportService::getSessionSummary` |
| `cash_register_discrepancies` | List sessions with cash discrepancies | `start_date`, `end_date` | `CashRegisterReportService::getDiscrepancies` |
| `daily_cash_close` | Get the cash close for a specific date | `date` (string) | `CashRegisterReportService::getDailyClose` |
| `active_promotions` | List currently active promotions | *(none)* | `PromotionReportService::getActivePromotions` |
| `promotion_usage_stats` | Usage stats for one promotion | `promotion_id` (number), `start_date`, `end_date` | `PromotionReportService::getUsageStats` |
| `quote_status_summary` | Quotes grouped by status | `start_date`, `end_date` | `QuoteInvoiceReportService::getQuoteStatusSummary` |
| `quote_conversion_rate` | Quote → sale conversion rate | `start_date`, `end_date` | `QuoteInvoiceReportService::getConversionRate` |
| `invoice_status_summary` | Invoices grouped by status | `start_date`, `end_date` | `QuoteInvoiceReportService::getInvoiceStatusSummary` |
| `expenses_by_category` | Expenses grouped by category | `start_date`, `end_date` | `ExpenseReportService::byCategory` |
| `expense_trend` | Monthly expense trend | `months` (number, optional, default 6) | `ExpenseReportService::trend` |
| `service_order_status_summary` | Service orders grouped by status | *(none)* | `ServiceOrderReportService::getStatusSummary` |
| `service_order_workload` | Workload per technician | `start_date`, `end_date` | `ServiceOrderReportService::getWorkloadByTechnician` |
| `service_order_turnaround` | Average turnaround time | `start_date`, `end_date` | `ServiceOrderReportService::getAverageTurnaroundTime` |
| `sales_by_employee` | Sales grouped by employee | `start_date`, `end_date` | `StaffPerformanceService::salesByEmployee` |
| `today_sales_summary` | Today's sales KPIs | *(none)* | `SalesDashboardService::getTodaySales` |
| `weekly_sales_trend` | Last 7 days sales trend | *(none)* | `SalesDashboardService::getWeeklyTrend` |
| `low_stock_products` | Products below stock threshold | `threshold` (number, optional, default 5) | `SalesDashboardService::getLowStockProducts` |
| `search_transactions` | Filtered transaction search | `status`, `payment_method`, `date_from`, `date_to`, `channel` (all optional strings) | `TransactionQueryService::search` — **replaces `recent_transactions`**, keep the old one as a thin wrapper or deprecate it |

---

## 3. Reference implementations (follow this pattern for the rest)

### Pattern A — wraps an existing method with zero new query logic

```php
Tool::as('cash_register_session_summary')
    ->for('Get the summary of a specific cash register session: totals by payment method and bank account reconciliation')
    ->withNumberParameter('session_id', 'The cash register session ID')
    ->using(function (int $session_id) use ($branchId) {
        $session = CashRegisterSession::where('branch_id', $branchId)->findOrFail($session_id);
        return json_encode([
            'totals' => $session->getCompletedPaymentTotals(),
            'bank_summary' => $session->calculateBankAccountSummary(),
        ]);
    }),
```

Note the `where('branch_id', $branchId)` before `findOrFail` — this is the tenant-scoping enforcement point. Every tool that accepts an ID must scope the lookup this way, not just trust the ID blindly.

### Pattern B — needs a new Service method (write it, then wrap it)

```php
// app/Services/CustomerReportService.php
class CustomerReportService
{
    public function getAccountStatement(int $branchId, int $customerId): array
    {
        $customer = Customer::where('branch_id', $branchId)->findOrFail($customerId);

        $movements = CustomerBalanceMovement::where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get();

        $running = 0;
        return $movements->map(function ($m) use (&$running) {
            $running += $m->amount;
            return [
                'date' => $m->created_at->toDateString(),
                'type' => $m->type,
                'amount' => $m->amount,
                'running_balance' => $running,
            ];
        })->toArray();
    }
}
```

```php
Tool::as('customer_account_statement')
    ->for('Get a customer\'s balance/credit account statement with running balance')
    ->withStringParameter('customer_query', 'Customer name, email, or phone to search for')
    ->using(function (string $customer_query) use ($branchId) {
        $customer = Customer::where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('name', 'like', "%{$customer_query}%")
                ->orWhere('email', 'like', "%{$customer_query}%")
                ->orWhere('phone', 'like', "%{$customer_query}%"))
            ->firstOrFail();

        return json_encode(app(CustomerReportService::class)->getAccountStatement($branchId, $customer->id));
    }),
```

This "search by fuzzy query, not by raw ID" pattern matches your existing `search_customers`/`search_products` tools — reuse it for any tool where the model won't reliably know a numeric ID (customers, promotions by name, etc.), and reserve numeric-ID parameters for cases like `cash_register_session_summary` where a prior tool call would have surfaced the ID first.

### Pattern C — extraction from a controller into a Service

```php
// app/Services/SalesDashboardService.php
class SalesDashboardService
{
    public function getTodaySales(int $branchId): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();

        return Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->selectRaw('SUM(subtotal - total_discount + total_tax) as total_sales')
            ->selectRaw('COUNT(*) as total_count')
            ->first()
            ->toArray();
    }
}
```

Then update `DashboardController@__invoke` to call `app(SalesDashboardService::class)->getTodaySales($branchId)` instead of the inline query — this fixes the "not extracted" issue flagged in the audit *and* gives you the AI tool for free.

---

## 4. Things to verify before writing the query (don't assume)

The audit couldn't confirm these — check the actual schema/code before implementing:
- Does `PromotionEffect` (or `PromotionRule`) have a foreign key back to `Transaction`/`TransactionItem` to compute usage stats? If not, `promotion_usage_stats` needs that link added first, or should be descoped.
- Does `Quote` have a column linking it to the `Transaction` it became (e.g. `converted_transaction_id`)? If not, `quote_conversion_rate` isn't computable as specified — check for an alternative signal (matching customer + date proximity is a weak substitute, flag if that's the only option).
- Does `ServiceOrder` have a `completed_at` timestamp, or does "completed" only exist as a status enum value with no timestamp? If the latter, turnaround time needs to come from `spatie/activitylog` history instead (check `ActivityLogService` for how it queries status-change history).

If any of these don't exist, add the minimal column/FK needed rather than approximating with a fragile query — a wrong number in a report is worse than a missing tool.

---

## 5. Implementation order

1. Wire up `ai_tool_executions` logging (Section 0.1) — non-negotiable, do first.
2. Evaluate + attempt the Prism migration (Section 6) — do this before step 3 if it succeeds, since migrating 6 tools now is cheaper than migrating 27 later.
3. `SalesDashboardService` + `TransactionQueryService` (Pattern C) — highest value, reuses logic you already wrote, just needs extraction.
4. `CashRegisterReportService` (Pattern A) — no new queries, fastest to ship.
5. `CustomerReportService`, `ExpenseReportService` — new but straightforward queries.
6. `QuoteInvoiceReportService`, `ServiceOrderReportService`, `StaffPerformanceService` — depend on the Section 4 verifications above; don't start until those are confirmed.
7. `PromotionReportService` — same caveat, verify the FK first.
8. Update the system prompt in `AiAgentManager::systemPrompt()` to mention the new categories of questions it can now answer (customers, cash register, promotions, quotes/invoices, expenses, service orders, staff performance) so DeepSeek knows to reach for them.
9. Re-test end-to-end with a handful of real subscriber-style questions per new domain, checking `ai_tool_executions` logs to confirm the right tool fired.

---

## 6. Migrate to Prism PHP (recommended, conditional)

**Why reconsider this now:** current Prism (v0.100.x) requires only PHP `^8.2` — the `^8.3` error hit earlier came from resolving a very old `v0.1.0`, not a real constraint. Prism also has **first-party DeepSeek support** (confirmed: Anthropic, DeepSeek, Groq, Mistral, Ollama, OpenAI, xAI, Perplexity are all first-party providers), which fits the model-agnostic goal better than maintaining a hand-rolled `AiProvider` interface. More importantly: look at the 9 documented bugs in `ai-agent-current-state.md` Section 8 — at least 5 of them (`tool_call_id` vs `tool_use_id`, empty-parameters-as-array, message-stripping, format translation) are exactly the class of low-level API-format problem a mature, widely-used library has already solved. Maintaining that translation layer by hand is ongoing bug surface for no benefit.

Bonus: the existing custom `Tool::as()->for()->withStringParameter()->using()` builder already mirrors Prism's real API almost exactly (this was intentional when it was first designed), so tool definitions themselves need minimal rewriting — the migration is mostly about the provider/orchestration layer, not the 27 tool definitions.

### Task for the coding agent

1. **Dry-run first, don't commit to anything yet:**
   ```bash
   composer require prism-php/prism:^0.100 --dry-run
   ```
   If this reports a conflict, **stop here** — leave the current `AiProvider`/`DeepSeekProvider` implementation exactly as-is, skip the rest of this section, and proceed with the tool catalog in Section 2 unmodified.

2. **If the dry run succeeds, install for real** (pin the version — Prism's own docs recommend this since the API still evolves):
   ```bash
   composer require prism-php/prism:^0.100
   php artisan vendor:publish --tag=prism-config
   ```
   Set the DeepSeek key in `.env` per whatever variable name `config/prism.php` generates (check the published file — don't guess the name).

3. **Rewrite `AiAgentManager::ask()`** to replace the manual `while ($step < $maxSteps)` loop with Prism's built-in multi-step handling:
   ```php
   use Prism\Prism\Facades\Prism;
   use Prism\Prism\Enums\Provider;

   $response = Prism::text()
       ->using(Provider::DeepSeek, $conversation->model)
       ->withSystemPrompt($this->systemPrompt($user))
       ->withMessages($this->history($conversation))
       ->withTools($tools)
       ->withMaxSteps(6)
       ->generate();
   ```
   Log each step's tool calls into `ai_tool_executions` here (per Section 0 item 1) — Prism exposes step-by-step data on the response object; check `$response->steps` for the shape.

4. **Delete these files entirely** — Prism replaces their function:
   - `packages/ai-agent/src/Providers/DeepSeekProvider.php`
   - `packages/ai-agent/src/Providers/AnthropicProvider.php` (if present)
   - `packages/ai-agent/src/Contracts/AiProvider.php`
   - Any standalone `formatMessageForApi()` / tool_call_id-translation helper

5. **Update tool definitions**: swap `use Ezyventas\AiAgent\Schema\Tool;` for `use Prism\Prism\Tool;` throughout `EzyVentasToolProvider`. Given how close the two APIs already are, this should be close to a find-and-replace rather than a rewrite — but verify each `withXParameter()` call still matches Prism's actual method names.

6. **Regression-test against all 9 failure modes** listed in `ai-agent-current-state.md` Section 8. None should resurface — Prism handles `tool_call_id` formatting, empty-parameter schemas, and message formatting internally — but confirm rather than assume.

### Also fix regardless of the Prism decision: DeepSeek model deprecation

The model currently configured (`deepseek-chat`, per the current-state audit) is a **deprecated alias that DeepSeek retires on 2026-07-24** — about two weeks out. Update the model identifier in the `ai.model` setting (and any `ai_conversations` rows using it going forward) to `deepseek-v4-pro` (matches current quality) or `deepseek-v4-flash` (cheaper/faster) before that date. This is unrelated to Prism and needs to happen either way.
