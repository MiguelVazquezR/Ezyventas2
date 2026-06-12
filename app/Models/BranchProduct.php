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
        'current_stock' => 'decimal:2',
        'reserved_stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
        'max_stock' => 'decimal:2',
    ];
}