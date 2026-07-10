<?php

namespace Ezyventas\AiAgent\Providers;

use Ezyventas\AiAgent\Contracts\AiProvider;
use Ezyventas\AiAgent\Contracts\AiProviderResponse;
use Ezyventas\AiAgent\Schema\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class OpenAIProvider implements AiProvider
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers'  => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120,
        ]);
    }

    public function chat(string $model, string $systemPrompt, array $messages, array $tools, string $apiKey): AiProviderResponse
    {
        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...array_map(fn (array $msg) => ['role' => $msg['role'], 'content' => $msg['content']], $messages),
        ];

        $body = [
            'model'       => $model,
            'messages'    => $formattedMessages,
            'max_tokens'  => 4096,
        ];

        if (! empty($tools)) {
            $body['tools'] = array_map(fn (Tool $t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t->name,
                    'description' => $t->description,
                    'parameters'  => $t->toProviderSchema()['input_schema'] ?? [],
                ],
            ], $tools);
        }

        try {
            $response = $this->http->post('chat/completions', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                'json'    => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI API error: ' . $e->getMessage(), 0, $e);
        }

        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = [
                'id'        => $tc['id'],
                'name'      => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
            ];
        }

        return new AiProviderResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $choice['finish_reason'] ?? null,
        );
    }
}
