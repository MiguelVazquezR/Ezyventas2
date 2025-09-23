<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    // use MustVerifyEmail;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'branch_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * // Obtiene la suscripción a la que pertenece el usuario.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Obtiene la suscripción del usuario a través de su sucursal.
     */
    public function subscription(): HasOneThrough
    {
        return $this->hasOneThrough(
            Subscription::class,
            Branch::class,
            'id', // Foreign key on branches table...
            'id', // Foreign key on subscriptions table...
            'branch_id', // Local key on users table...
            'subscription_id' // Local key on branches table...
        );
    }
    
    /**
     * // Obtiene las sucursales que este usuario gestiona.
     */
    // public function managedBranches(): HasMany
    // {
    //     return $this->hasMany(Branch::class, 'manager_id');
    // }

    /**
     * // Obtiene las transacciones registradas por este usuario.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * // Obtiene los gastos registrados por este usuario.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Obtiene todas las sesiones de caja asociadas a este usuario.
     */
    public function cashRegisterSessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    /**
     * AÑADIDO: Obtiene todas las configuraciones personalizadas del usuario.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(SettingValue::class, 'configurable');
    }
}
