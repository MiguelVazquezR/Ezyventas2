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
        'payment_method', 
        'invoiced', 
        'invoice_status',
        'status', // AÑADIDO
        'payment_details' // AÑADIDO
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoiced' => 'boolean',
        'invoice_status' => InvoiceStatus::class,
        'status' => SubscriptionPaymentStatus::class, // AÑADIDO
        'payment_details' => 'array', // AÑADIDO
    ];
    
    public function subscriptionVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVersion::class, 'subscription_version_id');
    }

    public function referralUsage(): HasOne
    {
        return $this->hasOne(ReferralUsage::class);
    }

    // AÑADIDO: Colección de media para el comprobante
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof_of_payment')
            ->singleFile(); // Solo permite un archivo
    }
}