<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'store_config_id',
        'transaction_id',
        'order_number',
        'status',
        'delivery_type',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'customer_notes',
        'subtotal',
        'delivery_fee',
        'total',
        'delivered_at',
    ];

    protected $appends = [
        'formatted_order_number',
        'whats_app_link',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $lastNumber = static::where('subscription_id', $order->subscription_id)->max('order_number') ?? 0;
                $order->order_number = $lastNumber + 1;
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function storeConfig(): BelongsTo
    {
        return $this->belongsTo(StoreConfig::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->latest();
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::Pending);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [OrderStatus::Reviewed, OrderStatus::InPreparation]);
    }

    public function scopeByStatus($query, OrderStatus $status)
    {
        return $query->where('status', $status);
    }

    // Helpers
    public function getFormattedOrderNumberAttribute(): string
    {
        $digits = $this->order_number < 10000 ? 4 : strlen((string) $this->order_number);
        return str_pad((string) $this->order_number, $digits, '0', STR_PAD_LEFT);
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function getCanBeCancelledAttribute(): bool
    {
        return !in_array($this->status, [OrderStatus::Delivered, OrderStatus::Cancelled]);
    }

    public function getWhatsAppLinkAttribute(): string
    {
        $phone = preg_replace('/[^0-9]/', '', $this->customer_phone);
        $message = urlencode("Hola {$this->customer_name}, gracias por tu pedido {$this->formatted_order_number} en " . ($this->storeConfig?->store_name ?? 'nuestra tienda') . ". ");
        return "https://wa.me/{$phone}?text={$message}";
    }

    public function logStatusChange(OrderStatus $from, OrderStatus $to, ?string $note = null, ?int $userId = null): void
    {
        $this->statusLogs()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'note' => $note,
            'user_id' => $userId,
        ]);

        // If transitioning to delivered, set delivered_at
        if ($to === OrderStatus::Delivered) {
            $this->update(['delivered_at' => now()]);
        }
    }
}
