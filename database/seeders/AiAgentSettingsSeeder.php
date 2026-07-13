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
                'description'   => 'Proveedor de inteligencia artificial (deepseek, anthropic, openai, groq, ollama)',
                'module'        => 'ai_agent',
                'level'         => 'subscription',
                'type'          => 'string',
                'default_value' => 'deepseek',
            ],
        );

        SettingDefinition::firstOrCreate(
            ['key' => 'ai.model'],
            [
                'name'          => 'AI Model',
                'description'   => 'Modelo de IA a utilizar (deepseek-v4-flash, deepseek-v4-pro, etc.)',
                'module'        => 'ai_agent',
                'level'         => 'subscription',
                'type'          => 'string',
                'default_value' => 'deepseek-v4-flash',
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
