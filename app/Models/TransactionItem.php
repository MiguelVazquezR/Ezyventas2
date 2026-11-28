<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TransactionItem extends Model
{
    use HasFactory;
    
    protected $table = 'transactions_items'; // Laravel adivinaría "transaction_items", lo especificamos para que coincida BD.

    protected $fillable = [
        'transaction_id',
        'itemable_id',
        'itemable_type',
        'description',
        'quantity',
        'unit_price',
        'discount_amount',
        'discount_reason',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            // 3 decimals: a bulk product is sold by weight (0.750 kg). The column
            // is decimal(10,3); casting to 2 rounded 0.001-0.009 away on read.
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Obtiene la transacción a la que pertenece este item.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Obtiene el modelo padre (Product, Service, etc.) de este item.
     */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }
}