<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SettingDefinition;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiAgentSettingsController extends Controller
{
    /**
     * Show the AI agent configuration page.
     */
    public function index(): Response
    {
        $definitions = SettingDefinition::where('module', 'ai_agent')
            ->where('level', 'platform')
            ->get()
            ->keyBy('key');

        $settings = [];
        foreach ($definitions as $key => $def) {
            $value = $def->default_value;

            // Decrypt encrypted values for display
            if ($def->type === 'encrypted_string' && $value) {
                try {
                    $value = decrypt($value);
                } catch (\Exception) {
                    $value = '';
                }
            }

            $settings[$key] = [
                'id'            => $def->id,
                'name'          => $def->name,
                'description'   => $def->description,
                'type'          => $def->type,
                'value'         => $value,
            ];
        }

        return Inertia::render('Admin/AiAgent/Settings', [
            'settings' => $settings,
        ]);
    }

    /**
     * Update platform-level AI agent settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'ai_provider'     => ['required', 'string', 'in:deepseek,anthropic,openai,groq,ollama'],
            'ai_model'        => ['required', 'string', 'max:100'],
            'ai_api_key'      => ['nullable', 'string', 'max:500'],
            'ai_token_limit'  => ['required', 'integer', 'min:0'],
        ]);

        // Map form keys (underscore) to database keys (dot notation)
        $keyMap = [
            'ai_provider'    => 'ai.provider',
            'ai_model'       => 'ai.model',
            'ai_api_key'     => 'ai.api_key',
            'ai_token_limit' => 'ai.token_limit',
        ];

        foreach ($validated as $formKey => $value) {
            $dbKey = $keyMap[$formKey] ?? $formKey;
            $definition = SettingDefinition::where('key', $dbKey)->first();

            if (! $definition) {
                continue;
            }

            // Encrypt API keys before storing
            if ($formKey === 'ai_api_key' && $value) {
                $value = encrypt($value);
            }

            $definition->default_value = (string) $value;
            $definition->save();
        }

        return back()->with('success', 'Configuración del Asistente IA actualizada.');
    }
}
