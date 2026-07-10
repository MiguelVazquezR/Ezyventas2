<?php

namespace Ezyventas\AiAgent\Schema;

readonly class ToolParameter
{
    public function __construct(
        public string $name,
        public string $type,        // 'string', 'number', 'boolean'
        public string $description,
        public bool $required = true,
    ) {}
}
