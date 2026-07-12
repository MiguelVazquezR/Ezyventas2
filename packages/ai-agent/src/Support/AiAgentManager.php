<?php

namespace Ezyventas\AiAgent\Support;

use Ezyventas\AiAgent\Contracts\AiProvider;
use Ezyventas\AiAgent\Contracts\AiProviderResponse;
use Ezyventas\AiAgent\Models\AiConversation;
use Ezyventas\AiAgent\Models\AiMessage;
use Ezyventas\AiAgent\Models\AiToolExecution;
use Ezyventas\AiAgent\Providers\AnthropicProvider;
use Ezyventas\AiAgent\Providers\DeepSeekProvider;
use Ezyventas\AiAgent\Providers\OpenAIProvider;
use Ezyventas\AiAgent\Schema\Tool;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class AiAgentManager
{
    public function __construct(
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * Send a user message within a conversation and return the assistant's reply.
     */
    public function ask(AiConversation $conversation, string $userMessage, Authenticatable $user): AiMessage
    {
        $tools = $this->tools->forUser($user);
        $provider = $this->resolveProvider($conversation->provider);
        $apiKey = $this->resolveApiKey($user);

        $messages = $this->history($conversation);
        // TODO: Append the new user message to messages list
        // (handled by the caller already creating the user message row, but we
        //  need to include it in the prompt — the history() method will pick it up
        //  if called after the user message is persisted)

        $systemPrompt = $this->systemPrompt($user);
        $maxSteps = (int) config('ai-agent.max_tool_steps', 6);

        $toolCallLog = [];
        $finalContent = '';
        $step = 0;

        // ── Tool calling loop ──
        while ($step < $maxSteps) {
            $step++;

            $response = $provider->chat(
                $conversation->model,
                $systemPrompt,
                $messages,
                $tools,
                $apiKey,
            );

            if (empty($response->toolCalls)) {
                // No more tools to call — this is the final answer
                $finalContent = $response->content;
                break;
            }

            // Append the assistant's tool-use request to messages
            $messages[] = $this->formatAssistantToolUseMessage($response);

            // Execute each tool and append results
            foreach ($response->toolCalls as $toolCall) {
                $tool = $this->findTool($tools, $toolCall['name']);

                $startMs = (int) (microtime(true) * 1000);

                try {
                    $result = $tool
                        ? $tool->execute($toolCall['arguments'])
                        : json_encode(['error' => "Tool '{$toolCall['name']}' not found"]);
                } catch (\Throwable $e) {
                    $result = json_encode(['error' => $e->getMessage()]);
                }

                $durationMs = (int) (microtime(true) * 1000) - $startMs;

                $toolCallLog[] = [
                    'tool_name'  => $toolCall['name'],
                    'arguments'  => $toolCall['arguments'],
                    'result'     => $result,
                    'duration_ms'=> $durationMs,
                ];

                $messages[] = [
                    'role'          => 'tool',
                    'tool_call_id'  => $toolCall['id'],
                    'content'       => $result,
                ];
            }

            // Reset the system prompt after tool results — Anthropic requires it,
            // OpenAI ignores it on subsequent calls
            $systemPrompt = '';
        }

        if ($step >= $maxSteps && empty($finalContent)) {
            $finalContent = 'Lo siento, el asistente alcanzó el límite de pasos sin poder completar la respuesta. Intenta con una pregunta más concreta.';
        }

        $assistantMessage = $conversation->messages()->create([
            'role'       => 'assistant',
            'content'    => $finalContent,
            'tool_calls' => $toolCallLog,
        ]);

        // Persist audit log for each tool execution
        $subscriptionId = $conversation->subscription_id;
        foreach ($toolCallLog as $log) {
            AiToolExecution::create([
                'ai_message_id'  => $assistantMessage->id,
                'subscription_id'=> $subscriptionId,
                'user_id'        => $user->id,
                'tool_name'      => $log['tool_name'],
                'arguments'      => $log['arguments'],
                'result'         => $log['result'],
                'duration_ms'    => $log['duration_ms'],
            ]);
        }

        return $assistantMessage;
    }

    /**
     * Build the conversation history array for the provider.
     */
    private function history(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (AiMessage $msg) => [
                'role'    => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();
    }

    /**
     * Find a tool by name in the tool array.
     */
    private function findTool(array $tools, string $name): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Format the assistant's tool-use request as a messages entry.
     */
    private function formatAssistantToolUseMessage(AiProviderResponse $response): array
    {
        return [
            'role'    => 'assistant',
            'content' => $response->content,
            'tool_calls' => $response->toolCalls,
        ];
    }

    /**
     * Resolve the AI provider instance by name.
     */
    private function resolveProvider(string $provider): AiProvider
    {
        return match ($provider) {
            'anthropic' => new AnthropicProvider,
            'deepseek'  => new DeepSeekProvider,
            'openai'    => new OpenAIProvider,
            default     => throw new RuntimeException("Unsupported AI provider: {$provider}"),
        };
    }

    /**
     * Resolve the API key for the user's subscription.
     */
    private function resolveApiKey(Authenticatable $user): string
    {
        $subscription = $user->branch->subscription;

        $apiKey = $this->getTenantSetting($subscription, 'ai.api_key');

        if ($apiKey) {
            return decrypt($apiKey);
        }

        return config('ai-agent.default_api_key')
            ?? throw new RuntimeException('No se ha configurado una clave de API para el asistente de IA.');
    }

    /**
     * Get a setting value for a subscription.
     */
    private function getTenantSetting($subscription, string $key): ?string
    {
        $definition = \App\Models\SettingDefinition::where('key', $key)->first();

        if (! $definition) {
            return null;
        }

        return $subscription->settings()
            ->where('setting_definition_id', $definition->id)
            ->value('value');
    }

    /**
     * Generate the system prompt scoped to the current tenant.
     */
    private function systemPrompt(Authenticatable $user): string
    {
        $businessName = $user->branch?->subscription?->business_name ?? 'EzyVentas';

        return "You are the reporting assistant for {$businessName}, "
            . 'a point-of-sale business. Answer only using tool results. '
            . "If a question requires data you don't have a tool for, say so — never invent numbers. "
            . 'Respond in the same language the user writes in.';
    }
}
