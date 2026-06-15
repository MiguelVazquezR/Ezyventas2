<?php

namespace App\Models;

use App\Traits\HasSubscription;
use App\Enums\SessionCashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SessionCashMovement extends Model
{
    use HasFactory, HasSubscription;

    protected $table = 'session_cash_movements';

    /**
     * Se añade 'user_id' para poder asociar el movimiento a un usuario.
     */
    protected $fillable = ['cash_register_session_id', 'user_id', 'type', 'amount', 'description', 'created_at', 'updated_at'];

    protected $casts = [
        'type' => SessionCashMovementType::class,
        'amount' => 'decimal:2',
    ];

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    /**
     * Se añade la relación para obtener el usuario que registró el movimiento.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    /**
     * Returns the subscription ID via cashRegisterSession -> cashRegister -> branch -> subscription.
     */
    public function getSubscriptionId(): ?int
    {
        $session = $this->relationLoaded('cashRegisterSession')
            ? $this->cashRegisterSession
            : $this->cashRegisterSession()->first();

        if (!$session) {
            return null;
        }

        return $session->getSubscriptionId();
    }
}
