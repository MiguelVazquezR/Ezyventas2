<?php

namespace App\Models;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\SessionCashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'opening_bank_balances',
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
            'opening_bank_balances' => 'array',
            'closing_cash_balance' => 'decimal:2',
            'calculated_cash_total' => 'decimal:2',
            'cash_difference' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO (REFACTOR)
    |--------------------------------------------------------------------------
    */

    /**
     * Registra una salida de efectivo de esta sesión de caja.
     */
    public function registerOutflow(float $amount, string $description, int $userId): SessionCashMovement
    {
        return $this->cashMovements()->create([
            'type' => SessionCashMovementType::OUTFLOW,
            'amount' => $amount,
            'description' => $description,
            'user_id' => $userId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cash_register_session_user');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(SessionCashMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}