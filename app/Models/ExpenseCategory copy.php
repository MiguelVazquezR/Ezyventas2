<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasFactory;
    
    protected $table = 'expense_categories';

    protected $fillable = [
        'name',
        'description',
        'subscription_id',
    ];
    
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
