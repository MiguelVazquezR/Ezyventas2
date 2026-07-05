<?php

namespace App\Models;

use App\Models\Billing\Invoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'invoice_items';

    protected $fillable = [
        'invoice_id',
        'product_id',
        'description',
        'quantity',
        'sat_unit_code',
        'unit_name',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'sat_product_code',
        'no_identificacion',
        'objeto_imp',
        'tax_type',
        'tax_rate',
        'retained_tax_type',
        'retained_tax_rate',
        'retained_tax_amount',
        'retentions',
    ];

    protected function casts(): array
    {
        return [
            'quantity'            => 'decimal:4',
            'unit_price'          => 'decimal:4',
            'subtotal'            => 'decimal:2',
            'discount_amount'     => 'decimal:2',
            'tax_amount'          => 'decimal:2',
            'total'               => 'decimal:2',
            'tax_rate'            => 'decimal:6',
            'retained_tax_rate'   => 'decimal:6',
            'retained_tax_amount' => 'decimal:2',
            'retentions'          => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate the line subtotal from quantity and unit price (before discount).
     */
    public function grossSubtotal(): float
    {
        return round($this->quantity * $this->unit_price, 2);
    }
}
