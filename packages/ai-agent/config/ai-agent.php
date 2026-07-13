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

];
