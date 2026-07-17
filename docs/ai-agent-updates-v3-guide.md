# AI Agent Feature Updates v3 — EzyVentas 2

> **Audience:** Coding agent (DeepSeek V4 Pro in VS Code). Grounded in `docs/ai-agent-update-audit-2.md`. Covers: fixing real gaps in the permission→tool mapping, properly testing permission filtering, fixing (or cleanly removing) the download link, a new "where do I find this" navigation tool, and branch awareness in the system prompt.

---

## 0. Key findings from this audit (read first)

- The previous audit's permission test **only confirmed the superadmin bypass** (`user_id === 1`, which passes everything via `Gate::before`). It never tested a real restricted employee. The conclusion "permission filtering is working" is **not actually verified** — Section 2 below fixes this properly.
- Comparing the 28 tool→permission mappings against the real sidebar (`AppMenu.vue`) surfaced **3 concrete mismatches** that likely explain what you're seeing — Section 1 fixes these.
- **`TrustProxies` is completely missing from the project.** This is the most likely root cause of the 403 in production — Section 3.
- Branch scoping is already correct everywhere (every tool derives `$branchId` from `$user->branch_id`, which the audit confirmed is a direct, non-session-based column — switching branches updates that column directly). The only gap is that the model doesn't know the branch's *name* to reference it naturally — Section 5 is a small addition, not an architecture fix.

---

## 1. Fix the permission→tool mapping gaps

Three real mismatches found by cross-checking against `AppMenu.vue` (the actual source of truth for what each permission is meant to gate):

| Tool | Current permission | Problem | Fix |
|---|---|---|---|
| `customer_purchase_history` | `customers.access` | Purchase history includes amounts spent — financial data. Your own app splits this exact distinction with `customers.see_financial_info` for a reason (basic contact info vs. financial info). An employee with `customers.access` but not `see_financial_info` can currently see spend data through this tool that they can't see in the actual Customers page. | Change to `customers.see_financial_info` |
| `low_stock_products` | `dashboard.see_sales` | Wrong permission entirely — this is inventory visibility, not sales. The permission list has a purpose-built one: `dashboard.see_inventory_details`. | Change to `dashboard.see_inventory_details` |
| `export_products_excel` | `products.access` | Missing the granular export permission your app already has (`products.import_export`), used elsewhere to separately gate exports from basic viewing. | Change to require **both** `products.access` and `products.import_export` |
| `invoice_status_summary` | `quotes.access` | The sidebar has a **separate** `invoices.access` permission (`Facturación` menu item) that wasn't in the original permission audit — invoices aren't part of the Cotizaciones module after all. | Change to `invoices.access` |

```php
// app/AiTools/EzyVentasToolProvider.php — definitions()

// customer_purchase_history
'permission' => 'customers.see_financial_info', // was 'customers.access'

// low_stock_products
'permission' => 'dashboard.see_inventory_details', // was 'dashboard.see_sales'

// invoice_status_summary
'permission' => 'invoices.access', // was 'quotes.access'
```

For `export_products_excel`, since the filter mechanism only supports a single permission string today, extend it to support an array:

```php
// tools() — update the filter to handle either a string or array of permissions
public function tools(Authenticatable $user): array
{
    return collect($this->definitions($user))
        ->filter(function ($def) use ($user) {
            $perms = (array) ($def['permission'] ?? []);
            return empty($perms) || collect($perms)->every(fn ($p) => $user->can($p));
        })
        ->map(fn ($def) => $def['tool'])
        ->values()
        ->all();
}
```

```php
// export_products_excel definition
'permission' => ['products.access', 'products.import_export'],
```

(Apply the same array-aware change to `categories()`, which uses the same filter logic.)

---

## 2. Properly verify permission filtering with a real restricted account

Don't trust a superadmin test again — it always passes regardless of what's actually wired correctly. Do this instead:

1. Create (or use an existing) test employee user with a role that has `products.access` but explicitly **not** `financial_reports.access` and **not** `customers.see_financial_info`.
2. Clear Spatie's permission cache before testing — stale cached role/permission mappings from earlier testing can produce misleading results: `php artisan permission:cache-reset`
3. Log in as that test employee, open the AI drawer, and ask: *"¿Cuáles son mis KPIs financieros este mes?"* and separately *"¿Cuánto ha gastado el cliente Juan Pérez?"*
4. Confirm — by temporarily logging the resolved tool names inside `AiAgentManager::ask()` right before the `Prism::text()` call, not by reading code — that `financial_report` and `customer_account_statement`/`top_customers` are **absent** from the array for this user.
5. Confirm the system prompt's dynamic category list (`categories()`) also excludes "financial reports" and the financial-info category for this same user — otherwise the model might still try to reference a topic it has no tool for, producing a confusing non-answer instead of cleanly not knowing about it.
6. Repeat with a role that has `financial_reports.access` and confirm the tool **is** present. Both directions matter — testing only the negative case isn't sufficient either.

If step 4 fails (the tool is still present for the restricted user even after the Section 1 fixes and a cache reset), the bug is not in `EzyVentasToolProvider` — it's in how `Gate::before` resolves module-based permission checks (audit section 1.4). Add a temporary `Log::debug()` inside `Gate::before` logging `$user->id`, `$ability`, and the return value for every check during one test session to see exactly where it diverges from the expected result.

---

## 3. Fix the download link 403 (try this before removing the tool)

### 3.1 The concrete, high-confidence fix

`TrustProxies` is entirely absent from this project — confirmed, not a guess. If the hosting environment sits behind any reverse proxy or load balancer (very common, including on many shared hosting setups), Laravel doesn't know to trust the `X-Forwarded-*` headers and will resolve the wrong scheme/host when validating a signed URL — an immediate, 100%-reproducible 403 regardless of TTL.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*'); // shared hosting: trust the immediate proxy; tighten to a specific IP if you have one
    // ...existing middleware registrations...
})
```

Also force the scheme explicitly in production so URL generation never depends on guessing from headers:

```php
// app/Providers/AppServiceProvider.php — boot()
if ($this->app->environment('production')) {
    URL::forceScheme('https');
}
```

### 3.2 Also fix `APP_URL` for production specifically

The audit found `APP_URL=http://localhost:8001` — correct for local dev, but **verify this is set to the exact production HTTPS URL** (no trailing slash) in your production `.env`, since that's a separate file/value from what was audited here. This is the other classic cause of signed-URL mismatches.

```bash
# production .env only
APP_URL=https://your-real-production-domain.com
```

```bash
php artisan config:clear
```

### 3.3 Test properly this time

Generate a real export through the chat in production, and click the resulting link **immediately** (not after waiting). If it works now, the `TrustProxies` fix was the cause. If it still 403s, check `storage/logs/laravel.log` right after the attempt (the audit found no log entries — meaning the failure may be happening at a layer that doesn't reach Laravel's logger, like the web server itself; check the Hostinger/nginx-level error log too, not just `laravel.log`).

### 3.4 Fallback — if it still doesn't work after 3.1–3.3

Given you'd rather not fight this indefinitely, here's the clean removal path:

```php
// app/AiTools/EzyVentasToolProvider.php — delete this entire definition block
[
    'permission' => ['products.access', 'products.import_export'],
    'category'   => 'downloadable Excel exports',
    'tool'       => (new Tool)->as('export_products_excel') /* ... */,
],
```

```php
// AiAgentManager::systemPrompt() — remove this line
. 'You can also generate downloadable Excel exports of the product catalog.';
```

No route/controller cleanup strictly required (dead code doesn't hurt), but you can also remove the `download` route and method from `AiChatController` if you want a fully clean removal.

---

## 4. New tool: "where do I find this" navigation helper

Mirrors `AppMenu.vue` in PHP so the agent can answer "¿dónde registro un gasto?" or "¿dónde veo mis cotizaciones?" with a real, clickable, permission-respecting link.

### 4.1 Registry class

```php
// app/AiTools/SiteNavigationRegistry.php
namespace App\AiTools;

use Illuminate\Contracts\Auth\Authenticatable;

class SiteNavigationRegistry
{
    // Keep this in sync with resources/js/Layouts/AppMenu.vue manually — there is no
    // automatic sync between the two today. Consider extracting a shared JSON source
    // later if this drifts often.
    private array $pages = [
        ['label' => 'Punto de venta', 'route' => 'pos.index', 'permission' => 'pos.access', 'keywords' => ['vender', 'venta', 'caja rápida']],
        ['label' => 'Reporte financiero', 'route' => 'financial-control.index', 'permission' => 'financial_reports.access', 'keywords' => ['finanzas', 'kpis', 'reporte financiero']],
        ['label' => 'Historial de ventas', 'route' => 'transactions.index', 'permission' => 'transactions.access', 'keywords' => ['ventas pasadas', 'transacciones', 'historial']],
        ['label' => 'Productos', 'route' => 'products.index', 'permission' => 'products.access', 'keywords' => ['inventario', 'catálogo', 'productos']],
        ['label' => 'Gastos', 'route' => 'expenses.index', 'permission' => 'expenses.access', 'keywords' => ['gastos', 'registrar pago', 'egresos']],
        ['label' => 'Clientes', 'route' => 'customers.index', 'permission' => 'customers.access', 'keywords' => ['clientes', 'compradores']],
        ['label' => 'Facturación', 'route' => 'invoices.index', 'permission' => 'invoices.access', 'keywords' => ['facturas', 'cfdi', 'facturación']],
        ['label' => 'Órdenes de servicio', 'route' => 'service-orders.index', 'permission' => 'services.orders.access', 'keywords' => ['servicios', 'reparación', 'orden']],
        ['label' => 'Cotizaciones', 'route' => 'quotes.index', 'permission' => 'quotes.access', 'keywords' => ['cotización', 'presupuesto']],
        ['label' => 'Cajas registradoras', 'route' => 'cash-registers.index', 'permission' => 'cash_registers.access', 'keywords' => ['caja', 'corte de caja']],
        ['label' => 'Historial de cortes', 'route' => 'cash-register-sessions.index', 'permission' => 'cash_registers.sessions.access', 'keywords' => ['corte', 'cierre de caja']],
        ['label' => 'Usuarios', 'route' => 'users.index', 'permission' => 'settings.users.access', 'keywords' => ['empleados', 'usuarios', 'permisos']],
        ['label' => 'Roles y permisos', 'route' => 'roles.index', 'permission' => 'settings.roles_permissions.access', 'keywords' => ['roles', 'permisos']],
        ['label' => 'Mi suscripción', 'route' => 'subscription.manage', 'permission' => null, 'keywords' => ['plan', 'suscripción', 'límite', 'pago', 'renovar']],
        // add the rest of AppMenu.vue's items here following the same shape
    ];

    public function searchFor(Authenticatable $user, string $query): array
    {
        $words = array_filter(explode(' ', mb_strtolower($query)), fn ($w) => mb_strlen($w) > 2);

        return collect($this->pages)
            ->filter(fn ($p) => $p['permission'] === null || $user->can($p['permission']))
            ->filter(fn ($p) => $this->matches($p, $words))
            ->map(fn ($p) => ['label' => $p['label'], 'url' => route($p['route'])])
            ->values()
            ->all();
    }

    private function matches(array $page, array $words): bool
    {
        $haystack = mb_strtolower($page['label'] . ' ' . implode(' ', $page['keywords']));
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) return true;
        }
        return false;
    }
}
```

Verify the exact route name for "Mi suscripción" (`subscription.manage` is a guess based on the `manage()` method seen in earlier audits — confirm before using).

### 4.2 Tool definition

```php
[
    'permission' => null, // gated internally per-page by searchFor(), not by a single blanket permission
    'category'   => 'navigation',
    'tool'       => (new Tool)->as('find_page_location')
        ->for('Find where in the system to do something or see certain information — e.g. "where do I register an expense", "where can I see cash register history". Returns page names with clickable links. Use this whenever the user asks "dónde", "cómo llego a", or similar navigation questions.')
        ->withStringParameter('query', 'What the user wants to find or do, in their own words')
        ->using(fn (string $query) => json_encode(app(SiteNavigationRegistry::class)->searchFor($user, $query))),
],
```

Add to the system prompt: `'If the user asks where to find something or how to navigate to a page, use find_page_location and present results as markdown links: [Label](url).'`

### 4.3 Frontend — render markdown links and route internal ones through Inertia

Current `renderContent()` in `AiChatDrawer.vue` only auto-links raw `https://` URLs. Add markdown-link parsing before that, and a click handler that uses Inertia's client-side routing for same-origin page links (avoiding a jarring full-page reload) while leaving downloads and external links as normal browser navigation:

```js
// AiChatDrawer.vue — script setup
import { router } from '@inertiajs/vue3';

function renderContent(text) {
    if (!text) return '';
    let html = text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        // markdown links: [label](url) — before the raw-URL autolink so it isn't double-processed
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\)\s]+)\)/g, '<a href="$2" data-chat-link="1">$1</a>')
        .replace(/\n/g, '<br>')
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" class="text-primary-500 underline">$1</a>');
    return html;
}

function onMessagesClick(e) {
    const link = e.target.closest('[data-chat-link]');
    if (!link) return;

    const url = new URL(link.href);
    const isDownload = url.pathname.startsWith('/ai-agent/download');

    if (url.origin === window.location.origin && !isDownload) {
        e.preventDefault();
        router.visit(url.pathname + url.search); // SPA navigation, no full reload
    }
    // else: same-origin download or external link — let the browser handle it normally
}
```

```html
<!-- messages container -->
<div ref="messagesContainer" class="..." @click="onMessagesClick">
```

The `isDownload` check is a simple path-prefix heuristic — if you later add other same-origin "download-like" routes outside `/ai-agent/download`, extend that check accordingly.

---

## 5. Branch awareness in the system prompt

Data scoping is already correct (confirmed: every tool resolves `$branchId` from `$user->branch_id`, and switching branches updates that column directly with no session indirection). The only gap is that the model can't *mention* the branch by name. Small addition:

```php
// AiAgentManager::systemPrompt()
$branchName = $user->branch?->name;

return "Today's date and time is {$today} (America/Mexico_City). "
    . ($branchName ? "You are currently helping a user at the \"{$branchName}\" branch — all data you retrieve is already scoped to this branch, mention it naturally when relevant (e.g. when the user might have multiple branches). " : '')
    . "You are the reporting assistant for {$businessName}, "
    // ...rest unchanged
    ;
```

No backend data-access change needed — this is purely so the assistant can say "en la sucursal Centro vendiste $X hoy" instead of just "$X" when it's useful context (e.g. if the subscription has more than one branch).

---

## 6. Implementation order

1. Section 1 (permission mapping fixes) — quick, no new infrastructure.
2. Section 2 (proper permission test with a real restricted account) — do this right after Section 1 to confirm the fixes actually work, not just that the code compiles.
3. Section 3.1–3.2 (TrustProxies + forceScheme + production `APP_URL` check) — test per 3.3 before deciding on the fallback in 3.4.
4. Section 5 (branch name in prompt) — trivial, do anytime.
5. Section 4 (navigation tool) — the biggest new piece; build the registry incrementally (start with 5-6 of the most common pages, expand from there) rather than trying to mirror the entire sidebar at once.
