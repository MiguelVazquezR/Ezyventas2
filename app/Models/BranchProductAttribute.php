<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchProductAttribute extends Pivot
{
    protected $table = 'branch_product_attribute';

    protected $fillable = [
        'branch_id',
        'product_attribute_id',
        'price_modifier',
        'current_stock',
        'reserved_stock',
        'location',
    ];

    protected $casts = [
        'price_modifier' => 'decimal:2',
        'current_stock' => 'decimal:2',
        'reserved_stock' => 'decimal:2',
    ];
}