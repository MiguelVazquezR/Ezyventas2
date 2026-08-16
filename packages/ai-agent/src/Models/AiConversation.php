<?php

namespace Ezyventas\AiAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }

    /**
     * Scope conversations visible to a given subscription.
     */
    public function scopeForSubscription($query, int $subscriptionId)
    {
        $column = config('ai-agent.tenant_fk_column', 'subscription_id');

        return $query->where($column, $subscriptionId);
    }
}
