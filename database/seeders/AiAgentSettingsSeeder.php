<?php

namespace Database\Seeders;

use App\Models\SettingDefinition;
use Illuminate\Database\Seeder;

class AiAgentSettingsSeeder extends Seeder
{
    public function run(): void
    {
        SettingDefinition::firstOrCreate(
            ['key' => 'ai.provider'],
            [
                'name'          => 'AI Provider',
                'description'   => 'Proveedor de inteligencia artificial (anthropic, openai, groq, ollama)',
                'module'        => 'ai_agent',
                'level'         => 'subscription',
                'type'          => 'string',
                'default_value' => 'anthropic',
            ],
        );

        SettingDefinition::firstOrCreate(
            ['key' => 'ai.model'],
            [
                'name'          => 'AI Model',
                'description'   => 'Modelo de IA a utilizar (claude-sonnet-5, gpt-4o, etc.)',
                'module'        => 'ai_agent',
                'level'         => 'subscription',
                'type'          => 'string',
                'default_value' => 'claude-sonnet-5',
            ],
        );

        SettingDefinition::firstOrCreate(
            ['key' => 'ai.api_key'],
            [
                'name'          => 'AI Provider API Key',
                'description'   => 'Clave de API del proveedor de IA (se almacena encriptada)',
                'module'        => 'ai_agent',
                'level'         => 'subscription',
                'type'          => 'encrypted_string',
                'default_value' => null,
            ],
        );
    }
}
