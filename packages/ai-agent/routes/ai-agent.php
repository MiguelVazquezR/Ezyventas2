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

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->prefix('ai-agent')
    ->as('ai-agent.')
    ->group(function () {
        Route::post('/conversations', [AiChatController::class, 'store'])
            ->name('conversations.store');

        Route::post('/conversations/{conversation}/messages', [AiChatController::class, 'sendMessage'])
            ->name('messages.store');

        Route::get('/usage', [AiChatController::class, 'usage'])
            ->name('usage');
    });

// Download route — protected by signed URL signature AND subscription scoping
Route::middleware(['auth:sanctum', config('jetstream.auth_session')])
    ->get('/ai-agent/download/{path}', [AiChatController::class, 'download'])
    ->name('ai-agent.download')
    ->where('path', '.*')
    ->middleware('signed');
