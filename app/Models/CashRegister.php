<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    use HasFactory;
    
    protected $table = 'cash_registers';

    protected $fillable = [
        'branch_id',
        'name',
        'is_active',
        'in_use',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'in_use' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
    
    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }
}