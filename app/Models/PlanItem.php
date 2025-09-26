<?php

namespace App\Models;

use App\Enums\PlanItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'type',
        'name',
        'description',
        'monthly_price',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'type' => PlanItemType::class,
        'monthly_price' => 'decimal:2',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}