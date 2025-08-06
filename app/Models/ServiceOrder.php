<?php

namespace App\Models;

use App\Enums\ServiceOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $table = 'service_orders';

    protected $fillable = [
        'branch_id',
        'user_id',
        'quote_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'technician_name',
        'status',
        'received_at',
        'promised_at',
        'item_description',
        'reported_problems',
        'technician_diagnosis',
        'final_total',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceOrderStatus::class,
            'received_at' => 'datetime',
            'promised_at' => 'datetime',
            'final_total' => 'decimal:2',
            'custom_fields' => 'array',
            'customer_address' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }
}
