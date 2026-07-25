<?php

namespace App\AiTools\Registrars;

use Illuminate\Contracts\Auth\Authenticatable;

interface ToolRegistrar
{
    /**
     * Return all tool definitions for this module.
     * Each definition must have 'permission', 'category', and 'tool' keys.
     *
     * @return array<int, array{permission: string|array|null, category: string, tool: \Prism\Prism\Tool}>
     */
    public function definitions(Authenticatable $user): array;
}