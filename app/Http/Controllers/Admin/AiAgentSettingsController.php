<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class AiAgentSettingsController extends Controller
{
    /**
     * Show the AI agent configuration page.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/AiAgent/Settings', [
            'provider'   => config('ai-agent.default_provider', 'deepseek'),
            'model'      => config('ai-agent.default_model', 'deepseek-v4-flash'),
            'apiKey'     => config('ai-agent.default_api_key', ''),
            'tokenLimit' => (int) config('ai-agent.default_monthly_tokens', 2_000_000),
        ]);
    }

    /**
     * Update platform-level AI agent settings via .env.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_provider'    => ['required', 'string', 'in:deepseek,anthropic,openai,groq,ollama'],
            'ai_model'       => ['required', 'string', 'max:100'],
            'ai_api_key'     => ['nullable', 'string', 'max:500'],
            'ai_token_limit' => ['required', 'integer', 'min:0'],
        ]);

        $envPath = base_path('.env');

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            return back()->with('error', 'El archivo .env no se puede escribir. Verifica los permisos.');
        }

        $envContents = file_get_contents($envPath);

        $envMap = [
            'AI_DEFAULT_PROVIDER'       => $validated['ai_provider'],
            'AI_DEFAULT_MODEL'          => $validated['ai_model'],
            'AI_DEFAULT_API_KEY'        => $validated['ai_api_key'] ?? '',
            'AI_DEFAULT_MONTHLY_TOKENS' => (string) $validated['ai_token_limit'],
        ];

        foreach ($envMap as $key => $value) {
            // Quote values that contain spaces or special characters
            $quotedValue = str_contains($value, ' ') || $value === '' ? '"' . $value . '"' : $value;

            if (preg_match("/^{$key}=/m", $envContents)) {
                // Replace existing key
                $envContents = preg_replace(
                    "/^{$key}=.*/m",
                    "{$key}={$quotedValue}",
                    $envContents
                );
            } else {
                // Append new key
                $envContents .= "\n{$key}={$quotedValue}";
            }
        }

        file_put_contents($envPath, $envContents, LOCK_EX);

        // Clear config cache so the new values take effect immediately
        Artisan::call('config:clear');

        return back()->with('success', 'Configuración del Asistente IA actualizada.');
    }
}