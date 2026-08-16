<?php

namespace Ezyventas\AiAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolExecution extends Model
{
    protected $table = 'ai_tool_executions';

    protected $fillable = [
        'ai_message_id',
        'subscription_id',
        'user_id',
        'tool_name',
        'arguments',
        'result',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }
}
