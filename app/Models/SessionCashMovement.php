<?php

namespace App\Models;

use App\Enums\SessionCashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SessionCashMovement extends Model
{
    use HasFactory;

    protected $table = 'session_cash_movements';

    protected $fillable = ['cash_register_session_id', 'type', 'amount', 'description'];

    protected $casts = [
        'type' => SessionCashMovementType::class,
        'amount' => 'decimal:2',
    ];

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}