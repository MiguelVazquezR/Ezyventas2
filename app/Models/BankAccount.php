<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
                    ->withPivot('is_favorite'); // <-- ¡AQUÍ ESTÁ LA LÍNEA CLAVE!
    }
}