<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchProduct extends Pivot
{
    protected $table = 'branch_product';

    protected $fillable = [
        'branch_id',
        'product_id',
        'current_stock',
        'reserved_stock',
        'min_stock',
        'max_stock',
        'location',
    ];

    protected $casts = [
        // The pivot columns are decimal(10,3): keep the third decimal so a bulk
        // product sold by weight does not lose its gram precision on read.
        'current_stock' => 'decimal:3',
        'reserved_stock' => 'decimal:3',
        'min_stock' => 'decimal:3',
        'max_stock' => 'decimal:3',
    ];
}