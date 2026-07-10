<?php

use Illuminate\Support\Facades\Route;
use Ezyventas\AiAgent\Http\Controllers\AiChatController;

/*
|--------------------------------------------------------------------------
| AI Agent Routes
|--------------------------------------------------------------------------
|
| Phases 1: synchronous chat + signed file downloads.
| Phase 11 (future): token-by-token streaming via queued job + Pusher.
|
*/

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'can:ai_agent.access'])
    ->prefix('ai-agent')
    ->as('ai-agent.')
    ->group(function () {
        Route::post('/conversations', [AiChatController::class, 'store'])
            ->name('conversations.store');

        Route::post('/conversations/{conversation}/messages', [AiChatController::class, 'sendMessage'])
            ->name('messages.store')
            ->middleware('can:ai_agent.access');

        Route::get('/download/{path}', [AiChatController::class, 'download'])
            ->name('download')
            ->where('path', '.*')
            ->middleware('signed');
    });
