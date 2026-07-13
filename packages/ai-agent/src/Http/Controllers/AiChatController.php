<?php

namespace Ezyventas\AiAgent\Http\Controllers;

use Ezyventas\AiAgent\Http\Requests\SendAiMessageRequest;
use Ezyventas\AiAgent\Models\AiConversation;
use Ezyventas\AiAgent\Support\AiAgentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiChatController extends Controller
{
    /**
     * Create a new conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $conversation = AiConversation::create([
            'subscription_id' => $subscription->id,
            'user_id'         => $user->id,
            'provider'        => $this->resolveProvider($subscription),
            'model'           => $this->resolveModel($subscription),
        ]);

        return response()->json([
            'conversation' => [
                'id'    => $conversation->id,
                'title' => $conversation->title,
            ],
        ]);
    }

    /**
     * Send a message and get the assistant reply (synchronous).
     */
    public function sendMessage(SendAiMessageRequest $request, AiConversation $conversation): JsonResponse
    {
        $user = Auth::user();

        $this->authorizeConversation($conversation, $user);

        set_time_limit((int) config('ai-agent.request_timeout', 60));

        // Persist user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => $request->message,
        ]);

        // Auto-title the conversation from the first user message
        if (! $conversation->title) {
            $conversation->update([
                'title' => mb_substr($request->message, 0, 100),
            ]);
        }

        $assistantMessage = app(AiAgentManager::class)->ask(
            $conversation, $request->message, $user
        );

        $limitExceeded = $assistantMessage->tool_calls['limit_exceeded'] ?? false;

        return response()->json([
            'message' => [
                'id'             => $assistantMessage->id,
                'content'        => $assistantMessage->content,
                'tool_calls'     => $assistantMessage->tool_calls,
                'limit_exceeded' => $limitExceeded,
            ],
        ]);
    }

    /**
     * Serve a signed download (Excel, PDF, txt).
     */
    public function download(Request $request, string $path): BinaryFileResponse
    {
        // The 'signed' middleware already validated the signature by this point.

        // Decode URL-safe base64: reverse -_ → +/ and add padding
        $decodedPath = base64_decode(strtr($path, '-_', '+/'));

        if (! $decodedPath || ! str_contains($decodedPath, '/')) {
            abort(400, 'Invalid file path.');
        }

        // Cross-subscription check
        $pathSegments = explode('/', $decodedPath);
        $pathSubscriptionId = (int) ($pathSegments[1] ?? 0);

        if ($pathSubscriptionId === 0 || $pathSubscriptionId !== $request->user()?->branch?->subscription_id) {
            abort(403, 'No tienes acceso a este archivo.');
        }

        $disk = Storage::disk(config('ai-agent.export_disk', 'local'));

        if (! $disk->exists($decodedPath)) {
            abort(404);
        }

        return response()->download($disk->path($decodedPath));
    }

    /**
     * Return the current month's AI token usage percentage for the authenticated user.
     */
    public function usage(Request $request): JsonResponse
    {
        $subscription = $request->user()->branch->subscription;
        $data = $subscription->getAiCreditLimitData();

        return response()->json([
            'percentage' => $data['percentage'],
        ]);
    }

    /**
     * Resolve AI provider from tenant settings with fallback.
     */
    private function resolveProvider($subscription): string
    {
        return $this->getTenantSetting($subscription, 'ai.provider')
            ?? config('ai-agent.default_provider', 'deepseek');
    }

    /**
     * Resolve AI model from tenant settings with fallback.
     */
    private function resolveModel($subscription): string
    {
        return $this->getTenantSetting($subscription, 'ai.model')
            ?? config('ai-agent.default_model', 'deepseek-v4-flash');
    }

    /**
     * Get a setting value for a subscription via the existing polymorphic settings system.
     */
    private function getTenantSetting($subscription, string $key): ?string
    {
        $definition = \App\Models\SettingDefinition::where('key', $key)->first();

        if (! $definition) {
            return null;
        }

        $value = $subscription->settings()
            ->where('setting_definition_id', $definition->id)
            ->value('value');

        return $value;
    }

    /**
     * Ensure the conversation belongs to the authenticated user's subscription.
     */
    private function authorizeConversation(AiConversation $conversation, $user): void
    {
        $subscription = $user->branch->subscription;

        $fkColumn = config('ai-agent.tenant_fk_column', 'subscription_id');

        if ($conversation->{$fkColumn} !== $subscription->id) {
            abort(403);
        }
    }
}
