<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageMonthly extends Model
{
    protected $table = 'ai_usage_monthly';

    protected $fillable = [
        'subscription_id',
        'year',
        'month',
        'credits_used',
        'total_tokens',
        'estimated_cost_usd',
    ];

    protected $casts = [
        'credits_used' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:4',
    ];
}
