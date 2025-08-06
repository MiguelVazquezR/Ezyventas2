<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'company_name',
        'name',
        'email',
        'phone',
        'address',
        'tax_id',
        'balance',
        'credit_limit',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Obtiene la sucursal "hogar" del cliente.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Obtiene todas las transacciones de este cliente.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Obtiene el historial de movimientos de saldo para este cliente.
     */
    public function balanceMovements(): HasMany
    {
        return $this->hasMany(CustomerBalanceMovement::class);
    }
}