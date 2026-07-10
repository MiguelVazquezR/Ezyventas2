<?php

namespace Ezyventas\AiAgent\Contracts;

interface AiProvider
{
    /**
     * Send a chat request and return the assistant response.
     *
     * @param  string  $model  e.g. "claude-sonnet-5", "gpt-4o"
     * @param  string  $systemPrompt
     * @param  array   $messages  [['role' => 'user'|'assistant', 'content' => '...'], ...]
     * @param  array   $tools     Array of Ezyventas\AiAgent\Schema\Tool
     * @param  string  $apiKey
     * @return AiProviderResponse  [content, toolCalls, finishReason]
     */
    public function chat(string $model, string $systemPrompt, array $messages, array $tools, string $apiKey): AiProviderResponse;
}
