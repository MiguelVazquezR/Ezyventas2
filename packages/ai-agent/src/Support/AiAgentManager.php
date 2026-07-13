<?php

namespace Ezyventas\AiAgent\Support;

use Ezyventas\AiAgent\Models\AiConversation;
use Ezyventas\AiAgent\Models\AiMessage;
use Ezyventas\AiAgent\Models\AiToolExecution;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage as PrismAssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage as PrismUserMessage;
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
        // Enforce monthly token limit before calling the LLM
        $subscription = $user->branch->subscription;
        $limitData = $subscription->getAiCreditLimitData();

        if ($limitData['remaining'] <= 0) {
            return $conversation->messages()->create([
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => ['limit_exceeded' => true, 'limit' => $limitData['limit']],
            ]);
        }

        $tools = $this->tools->forUser($user);
        $apiKey = $this->resolveApiKey($user);
        $prismMessages = $this->buildPrismMessages($conversation);
        $systemPrompt = $this->systemPrompt($user);
        $maxSteps = (int) config('ai-agent.max_tool_steps', 6);

        $response = Prism::text()
            ->using(Provider::from($conversation->provider), $conversation->model, ['api_key' => $apiKey])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($prismMessages)
            ->withTools($tools)
            ->withMaxSteps($maxSteps)
            ->generate();

        // Capture token usage for admin visibility
        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;
        $totalTokens = $promptTokens + $completionTokens;

        $pricing = config("ai-agent.pricing_usd_per_million_tokens.{$conversation->model}");
        $costUsd = $pricing
            ? ($promptTokens / 1_000_000 * $pricing['input']) + ($completionTokens / 1_000_000 * $pricing['output'])
            : 0;

        // Increment monthly token usage
        \App\Models\AiUsageMonthly::firstOrCreate([
            'subscription_id' => $subscription->id,
            'year'            => now()->year,
            'month'           => now()->month,
        ]);

        \App\Models\AiUsageMonthly::where([
            'subscription_id' => $subscription->id,
            'year'            => now()->year,
            'month'           => now()->month,
        ])->increment('total_tokens', $totalTokens);

        \App\Models\AiUsageMonthly::where([
            'subscription_id' => $subscription->id,
            'year'            => now()->year,
            'month'           => now()->month,
        ])->increment('estimated_cost_usd', round($costUsd, 4));

        // Build tool call log from Prism response steps
        $toolCallLog = [];
        foreach ($response->steps as $step) {
            foreach ($step->toolCalls as $index => $toolCall) {
                $toolResult = $step->toolResults[$index] ?? null;

                $toolCallLog[] = [
                    'tool_name'   => $toolCall->name,
                    'arguments'   => $toolCall->arguments(),
                    'result'      => $toolResult?->result ?? null,
                    'duration_ms' => null, // Prism doesn't expose per-tool timing
                ];
            }
        }

        $finalContent = $response->text ?: 'Lo siento, el asistente no pudo generar una respuesta. Intenta con una pregunta más concreta.';

        $assistantMessage = $conversation->messages()->create([
            'role'       => 'assistant',
            'content'    => $finalContent,
            'tool_calls' => $toolCallLog,
        ]);

        // Persist audit log for each tool execution
        $subscriptionId = $conversation->subscription_id;
        foreach ($toolCallLog as $log) {
            AiToolExecution::create([
                'ai_message_id'   => $assistantMessage->id,
                'subscription_id' => $subscriptionId,
                'user_id'         => $user->id,
                'tool_name'       => $log['tool_name'],
                'arguments'       => $log['arguments'],
                'result'          => is_string($log['result']) ? $log['result'] : json_encode($log['result']),
                'duration_ms'     => $log['duration_ms'],
            ]);
        }

        return $assistantMessage;
    }

    /**
     * Build Prism message objects from the conversation history.
     *
     * Only role + content are passed to the provider. The tool_calls stored
     * on assistant messages are audit metadata (not API-level tool_call data)
     * — they were already resolved in a previous turn and the final answer is
     * in the content field. Reconstructing tool calls from history would
     * require matching tool_call_ids with tool result messages that don't
     * exist in the DB (intermediate tool messages are never persisted).
     */
    private function buildPrismMessages(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(function (AiMessage $msg): \Prism\Prism\Contracts\Message {
                return match ($msg->role) {
                    'user'      => new PrismUserMessage($msg->content),
                    'assistant' => new PrismAssistantMessage($msg->content ?? ''),
                    default     => new PrismUserMessage($msg->content ?? ''),
                };
            })
            ->values()
            ->all();
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
        $today = now()->locale('es')->translatedFormat('l, d \d\e F \d\e Y, H:i \h\r\s');

        $categories = $this->tools->categoriesForUser($user);
        $categoryList = ! empty($categories) ? implode(', ', $categories) : 'sales, inventory, customers, products, expenses, and cash register sessions';

        return "Today's date and time is {$today} (America/Mexico_City). "
            . 'Always use this as "today" for any relative date calculation — "last 3 months", "this week", "yesterday" — never infer or assume a different date. '
            . "You are the reporting assistant for {$businessName}, "
            . 'a point-of-sale business. Answer only using tool results. '
            . "If a question requires data you don't have a tool for, say so — never invent numbers. "
            . 'Respond in the same language the user writes in. '
            . 'You can answer questions about: '
            . $categoryList . '. '
            . 'You can also generate downloadable Excel exports of the product catalog.';
    }
}
