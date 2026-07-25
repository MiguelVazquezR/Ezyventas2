<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Fallback provider when no tenant-specific setting is configured.
    | Supported: "deepseek", "anthropic", "openai", "groq", "ollama"
    |
    */

    'default_provider' => env('AI_DEFAULT_PROVIDER', 'deepseek'),

    /*
    |--------------------------------------------------------------------------
    | Default AI Model
    |--------------------------------------------------------------------------
    |
    | Fallback model when no tenant-specific setting is configured.
    |
    */

    'default_model' => env('AI_DEFAULT_MODEL', 'deepseek-v4-flash'),

    /*
    |--------------------------------------------------------------------------
    | Default API Key
    |--------------------------------------------------------------------------
    |
    | Platform-wide fallback API key. Overridden per-tenant via settings.
    |
    */

    'default_api_key' => env('AI_DEFAULT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Max Tool Steps
    |--------------------------------------------------------------------------
    |
    | Hard cap on how many tool calls the LLM can make within a single message
    | response. Prevents runaway tool loops and controls cost.
    |
    */

    'max_tool_steps' => 6,

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum execution time in seconds for a single chat request.
    | Bumped to avoid shared-hosting timeouts while the LLM + tools run.
    |
    */

    'request_timeout' => 60,

    /*
    |--------------------------------------------------------------------------
    | Signed Download URL TTL
    |--------------------------------------------------------------------------
    |
    | Minutes before a generated file download link expires.
    |
    */

    'download_url_ttl' => 15,

    /*
    |--------------------------------------------------------------------------
    | Export Storage Disk
    |--------------------------------------------------------------------------
    |
    | Disk where AI-generated export files (Excel, PDF, txt) are stored.
    |
    */

    'export_disk' => env('AI_EXPORT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Tenant FK Column
    |--------------------------------------------------------------------------
    |
    | Column name on the ai_conversations table that references the tenant
    | model. Override this in projects that use "company_id" or "team_id".
    |
    */

    'tenant_fk_column' => 'subscription_id',

    /*
    |--------------------------------------------------------------------------
    | Default Monthly AI Tokens
    |--------------------------------------------------------------------------
    |
    | Free baseline token limit given to every subscription that existed before
    | the AI token limit feature shipped and hasn't purchased the
    | limit_ai_credits PlanItem yet. Default: 2 million tokens/month.
    |
    */

    'default_monthly_tokens' => (int) env('AI_DEFAULT_MONTHLY_TOKENS', 2_000_000),

    /*
    |--------------------------------------------------------------------------
    | AI Model Pricing (USD per million tokens)
    |--------------------------------------------------------------------------
    |
    | Used to estimate cost for admin visibility only — does not feed into
    | subscriber-facing credit math. Update these when provider pricing changes.
    |
    */

    'pricing_usd_per_million_tokens' => [
        'deepseek-v4-flash' => ['input' => 0.14, 'output' => 0.28],
        'deepseek-v4-pro'   => ['input' => 1.10, 'output' => 4.40],
    ],

];
