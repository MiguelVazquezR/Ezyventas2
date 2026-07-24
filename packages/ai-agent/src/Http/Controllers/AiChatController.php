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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiChatController extends Controller
{
    /**
     * List all conversations for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $conversations = AiConversation::where('subscription_id', $subscriptionId)
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'created_at']);

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

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
        ]);

        return response()->json([
            'conversation' => [
                'id'    => $conversation->id,
                'title' => $conversation->title,
            ],
        ]);
    }

    /**
     * Show a single conversation with its messages.
     */
    public function show(AiConversation $conversation): JsonResponse
    {
        $user = Auth::user();

        $this->authorizeConversation($conversation, $user);

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'tool_calls'])
            ->map(function ($msg) {
                $data = [
                    'id'      => $msg->id,
                    'role'    => $msg->role,
                    'content' => $msg->content,
                ];

                if ($msg->tool_calls) {
                    $data['tool_calls'] = $msg->tool_calls;
                    $data['limitExceeded'] = $msg->tool_calls['limit_exceeded'] ?? false;
                    $data['moduleInactive'] = $msg->tool_calls['module_inactive'] ?? false;
                }

                return $data;
            });

        return response()->json([
            'conversation' => [
                'id'    => $conversation->id,
                'title' => $conversation->title,
            ],
            'messages' => $messages,
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
        $moduleInactive = $assistantMessage->tool_calls['module_inactive'] ?? false;

        return response()->json([
            'message' => [
                'id'              => $assistantMessage->id,
                'content'         => $assistantMessage->content,
                'tool_calls'      => $assistantMessage->tool_calls,
                'limit_exceeded'  => $limitExceeded,
                'module_inactive' => $moduleInactive,
            ],
        ]);
    }

    /**
     * Delete all conversations for the authenticated user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        AiConversation::where('subscription_id', $subscriptionId)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'message' => 'Historial eliminado.',
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