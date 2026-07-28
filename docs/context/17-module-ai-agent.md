# 17 — AI Agent Module

---

## What It Does
AI-powered chat assistant embedded in the app. Built as a local Composer package (`packages/ai-agent/`), it provides a conversational interface for users to query their business data, get insights, and potentially perform actions. Includes usage tracking (credits, tokens, cost estimation) and admin-configurable settings.

All configuration is **global** — provider, model, API key, and monthly token limit are read from `.env` and can be updated via the admin panel at `/admin/ai-agent`.

---

## Key Files

### Backend (Package: `packages/ai-agent/`)
| File | Role |
|---|---|
| `packages/ai-agent/src/` | `Ezyventas\AiAgent` namespace |
| `packages/ai-agent/routes/ai-agent.php` | AI agent API routes |
| `packages/ai-agent/src/Support/AiAgentManager.php` | Core AI conversation orchestration |
| `packages/ai-agent/src/Http/Controllers/AiChatController.php` | Chat API endpoints |
| `packages/ai-agent/config/ai-agent.php` | Package configuration (reads from `.env`) |
| `packages/ai-agent/src/Models/AiConversation.php` | Conversation model |
| `packages/ai-agent/src/Models/AiMessage.php` | Chat message model |
| `packages/ai-agent/src/Models/AiToolExecution.php` | Tool execution audit log |
| `packages/ai-agent/src/Support/ToolRegistry.php` | Tool resolution for authenticated users |
| `app/Models/AiUsageMonthly.php` | Monthly usage tracking per subscription |
| `app/Http/Controllers/Admin/AiAgentSettingsController.php` | Admin AI configuration (reads/writes `.env`) |
| `config/prism.php` | Prism PHP configuration (AI provider abstraction) |
| `routes/web/super-admin.php` | Admin AI routes |

### Frontend
| File | Purpose |
|---|---|
| `Components/AiChatDrawer.vue` | AI chat sidebar/drawer component |
| `Pages/Admin/AiAgent/Settings.vue` | Admin AI settings |
| `composables/useAiChat.js` | Chat composable (state management + API calls) |

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

### Configuration Flow

All AI configuration is **global** and stored in `.env`:

```
AI_DEFAULT_PROVIDER=deepseek
AI_DEFAULT_MODEL=deepseek-v4-flash
AI_DEFAULT_API_KEY=sk-xxxxxxxx
AI_DEFAULT_MONTHLY_TOKENS=2000000
```

These are read by `config/ai-agent.php` and consumed directly by `AiAgentManager` and `AiChatController`. The admin panel at `/admin/ai-agent` reads and writes these `.env` values — there is no database-backed tenant-level configuration for AI settings.

### Usage Tracking
`AiUsageMonthly` records per-subscription, per-month:
- `credits_used` — Credit consumption
- `total_tokens` — Token usage
- `estimated_cost_usd` — Cost estimation

The monthly token limit is enforced against `config('ai-agent.default_monthly_tokens')` (from `.env`).

---

## Main Endpoints

### AI Agent Routes (from `packages/ai-agent/routes/ai-agent.php`)
Loaded in `routes/web.php` with the web middleware group.

| Method | URI | Name | Description |
|---|---|---|---|
| POST | `/ai-agent/conversations` | `ai-agent.conversations.store` | Create new conversation |
| POST | `/ai-agent/conversations/{conversation}/messages` | `ai-agent.messages.store` | Send message & get reply |
| GET | `/ai-agent/usage` | `ai-agent.usage` | Get monthly token usage percentage |
| GET | `/ai-agent/download/{path}` | `ai-agent.download` | Signed download (Excel, PDF, txt) |

### Admin (`/admin/ai-agent`)
- `GET /admin/ai-agent` — `admin.ai-agent.index` — View AI settings (reads from `.env`)
- `PUT /admin/ai-agent` — `admin.ai-agent.update` — Update AI settings (writes to `.env`)

---

## Database Tables

| Table | Purpose |
|---|---|
| `ai_conversations` | Conversation metadata (subscription_id, user_id, title) |
| `ai_messages` | Chat messages (role, content, tool_calls JSON) |
| `ai_tool_executions` | Audit log for each tool call |
| `ai_usage_monthly` | Per-subscription, per-month token/cost tracking |

---

## Dependencies
- **Prism PHP**: LLM provider abstraction
- **Subscriptions**: Usage tracked per subscription; credit limits checked on Subscription model

---

## Known Limitations / Technical Debt
1. **Prism is v0.100** — Pre-1.0 library with potential API instability. Breaking changes expected.
2. **Package is local only** — The AI agent is a local composer package, not published to Packagist. Changes affect all environments simultaneously.
3. **No streaming support confirmed** — Check if Prism v0.100 supports streaming responses.
4. **Credit system is basic** — Monthly tracking only; no real-time credit deduction or hard limits enforced at request time.
5. **Tool system is evolving** — The `docs/ai-agent-tool-expansion-guide.md` suggests the tool system is actively being developed.
6. **.env writes from admin panel** — The admin settings page writes directly to `.env`. This requires write permissions on the file and `config:clear` is called automatically after each update.

---