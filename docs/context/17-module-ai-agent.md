# 17 — AI Agent Module

---

## What It Does
AI-powered chat assistant embedded in the app. Built as a local Composer package (`packages/ai-agent/`), it provides a conversational interface for users to query their business data, get insights, and potentially perform actions. Includes usage tracking (credits, tokens, cost estimation) and admin-configurable settings.

---

## Key Files

### Backend (Package: `packages/ai-agent/`)
| File | Role |
|---|---|
| `packages/ai-agent/src/` | `Ezyventas\AiAgent` namespace |
| `packages/ai-agent/routes/ai-agent.php` | AI agent API routes |
| `app/Models/AiUsageMonthly.php` | Monthly usage tracking per subscription |
| `app/Http/Controllers/Admin/AiAgentSettingsController.php` | Admin AI configuration |
| `config/prism.php` | Prism PHP configuration (AI provider abstraction) |
| `routes/web/super-admin.php` | Admin AI routes |

### Frontend
| File | Purpose |
|---|---|
| `Components/AiChatDrawer.vue` | AI chat sidebar/drawer component |
| `Pages/Admin/AiAgent/Settings.vue` | Admin AI settings |

---

## Architecture

The AI agent uses the **Prism PHP** library (`prism-php/prism` v0.100) as an abstraction layer for LLM providers. The package is loaded as a local composer dependency with symlink:

```json
"repositories": [{
    "type": "path",
    "url": "packages/ai-agent",
    "options": { "symlink": true }
}]
```

### Usage Tracking
`AiUsageMonthly` records per-subscription, per-month:
- `credits_used` — Credit consumption
- `total_tokens` — Token usage
- `estimated_cost_usd` — Cost estimation

---

## Main Endpoints

### AI Agent Routes (from `packages/ai-agent/routes/ai-agent.php`)
Loaded in `routes/web.php` with the web middleware group. Exact endpoints depend on the package implementation (check current package code).

### Admin (`/admin/ai-agent`)
- `GET /admin/ai-agent` — `admin.ai-agent.index` — View AI settings
- `PUT /admin/ai-agent` — `admin.ai-agent.update` — Update AI settings

---

## Dependencies
- **Prism PHP**: LLM provider abstraction
- **Subscriptions**: Usage tracked per subscription; credit limits checked on Subscription model
- **Existing docs**: `docs/ai-agent-*.md` files contain implementation details, integration guides, and tool expansion guides

---

## Known Limitations / Technical Debt
1. **Prism is v0.100** — Pre-1.0 library with potential API instability. Breaking changes expected.
2. **Package is local only** — The AI agent is a local composer package, not published to Packagist. Changes affect all environments simultaneously.
3. **Documentation may be outdated** — Check `docs/ai-agent-current-state.md` and related files for the most recent implementation status vs. planned features.
4. **No streaming support confirmed** — Check if Prism v0.100 supports streaming responses.
5. **Credit system is basic** — Monthly tracking only; no real-time credit deduction or hard limits enforced at request time.
6. **Tool system is evolving** — The `docs/ai-agent-tool-expansion-guide.md` suggests the tool system is actively being developed.

---