<?php

namespace Ezyventas\AiAgent\Contracts;

readonly class AiProviderResponse
{
    public function __construct(
        public string $content,
        public array $toolCalls,    // [['id' => '...', 'name' => '...', 'arguments' => [...]], ...]
        public ?string $finishReason = null,
    ) {}
}
