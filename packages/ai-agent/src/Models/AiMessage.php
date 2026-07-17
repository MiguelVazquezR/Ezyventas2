<?php

namespace Ezyventas\AiAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tool_calls',
    ];

    protected $casts = [
        'tool_calls' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(AiToolExecution::class);
    }
}
