<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'bank_name',
        'owner_name',
        'account_name',
        'account_number',
        'card_number',
        'clabe',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    /**
     * Obtiene la suscripción a la que pertenece la cuenta.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Obtiene las sucursales a las que esta cuenta está asignada.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'bank_account_branch')
            ->withPivot('is_favorite');
    }

    // --- NUEVAS RELACIONES ---

    /**
     * Obtiene todos los pagos (ingresos) asociados a esta cuenta.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Obtiene todos los gastos (egresos) asociados a esta cuenta.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(BankAccountTransfer::class, 'from_account_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(BankAccountTransfer::class, 'to_account_id');
    }
}
