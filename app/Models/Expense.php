<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'folio',
        'user_id',
        'amount',
        'expense_category_id',
        'expense_date',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }
    
    /**
     * Obtiene el usuario que registró el gasto.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtiene la categoría del gasto.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}