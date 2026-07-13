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

        return response()->json([
            'message' => [
                'id'        => $assistantMessage->id,
                'content'   => $assistantMessage->content,
                'tool_calls'=> $assistantMessage->tool_calls,
            ],
        ]);
    }

    /**
     * Serve a signed download (Excel, PDF, txt).
     */
    public function download(Request $request, string $path): BinaryFileResponse
    {
        if (! $request->hasValidSignature()) {
            abort(401);
        }

        // Decode URL-safe base64: reverse -_ → +/ and add padding
        $path = base64_decode(strtr($path, '-_', '+/'));

        if (! $path || ! str_contains($path, '/')) {
            abort(400, 'Invalid file path.');
        }

        $disk = Storage::disk(config('ai-agent.export_disk', 'local'));

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->download($disk->path($path));
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
