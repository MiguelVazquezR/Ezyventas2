<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia; // AÑADIDO
use Spatie\MediaLibrary\InteractsWithMedia; // AÑADIDO

class SubscriptionPayment extends Model implements HasMedia // AÑADIDO HasMedia
{
    use HasFactory, InteractsWithMedia; // AÑADIDO InteractsWithMedia
    
    protected $table = 'subscription_payments';

    protected $fillable = [
        'subscription_version_id', 
        'amount',
        'referral_discount_pct',
        'referral_discount_amount',
        'payment_method', 
        'invoiced', 
        'invoice_status',
        'status',
        'payment_details'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'referral_discount_pct' => 'decimal:2',
        'referral_discount_amount' => 'decimal:2',
        'invoiced' => 'boolean',
        'invoice_status' => InvoiceStatus::class,
        'status' => SubscriptionPaymentStatus::class,
        'payment_details' => 'array',
    ];
    
    public function subscriptionVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVersion::class, 'subscription_version_id');
    }

    // AÑADIDO: Colección de media para el comprobante
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof_of_payment')
            ->singleFile(); // Solo permite un archivo
    }
}