<?php

namespace Ezyventas\AiAgent\Providers;

use Ezyventas\AiAgent\Contracts\AiProvider;
use Ezyventas\AiAgent\Contracts\AiProviderResponse;
use Ezyventas\AiAgent\Schema\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class AnthropicProvider implements AiProvider
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers'  => [
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'timeout' => 120,
        ]);
    }

    public function chat(string $model, string $systemPrompt, array $messages, array $tools, string $apiKey): AiProviderResponse
    {
        $body = [
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $systemPrompt,
            'messages'   => $this->formatMessages($messages),
        ];

        if (! empty($tools)) {
            $body['tools'] = array_map(fn (Tool $t) => $t->toProviderSchema(), $tools);
        }

        try {
            $response = $this->http->post('messages', [
                'headers' => ['x-api-key' => $apiKey],
                'json'    => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Anthropic API error: ' . $e->getMessage(), 0, $e);
        }

        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id'        => $block['id'],
                    'name'      => $block['name'],
                    'arguments' => $block['input'],
                ];
            }
        }

        return new AiProviderResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $data['stop_reason'] ?? null,
        );
    }

    private function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            // Handle tool results
            if ($msg['role'] === 'tool') {
                return [
                    'role'         => 'user',
                    'content'      => [[
                        'type'        => 'tool_result',
                        'tool_use_id' => $msg['tool_use_id'],
                        'content'     => $msg['content'],
                    ]],
                ];
            }

            return [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $messages);
    }
}
