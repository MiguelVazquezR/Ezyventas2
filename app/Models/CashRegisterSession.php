<?php

namespace App\Models;

use App\Enums\CashRegisterSessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends Model
{
    use HasFactory;

    protected $table = 'cash_register_sessions';

    protected $fillable = [
        'cash_register_id',
        'user_id',
        'opened_at',
        'closed_at',
        'status',
        'opening_cash_balance',
        'closing_cash_balance',
        'calculated_cash_total',
        'cash_difference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashRegisterSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_balance' => 'decimal:2',
            'closing_cash_balance' => 'decimal:2',
            'calculated_cash_total' => 'decimal:2',
            'cash_difference' => 'decimal:2',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(SessionCashMovement::class);
    }
}
