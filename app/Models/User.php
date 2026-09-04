<?php

namespace App\Models;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\TransactionStatus;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
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
        'is_active',
        'branch_id',
        'email_verified_at',
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
     * Verify the e-mail with an OTP code instead of the default verification
     * link. This replaces the framework's VerifyEmail notification and is used
     * both on registration and when the user changes their e-mail address.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(\App\Services\Auth\EmailVerificationCodeService::class)->send($this);
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
     * CORRECCIÓN: Obtiene todas las sesiones de caja en las que este usuario ha participado.
     */
    public function cashRegisterSessions(): BelongsToMany
    {
        return $this->belongsToMany(CashRegisterSession::class, 'cash_register_session_user');
    }

    /**
     * AÑADIDO: Obtiene todas las configuraciones personalizadas del usuario.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(SettingValue::class, 'configurable');
    }

    /**
     * REFACTOR: Helper semántico para saber si el usuario es el propietario/admin principal.
     */
    public function isOwner(): bool
    {
        // En este sistema, el dueño no tiene roles asignados.
        return !$this->roles()->exists();
    }

    /**
     * Obtiene los prefijos de los módulos a los que el propietario de la suscripción tiene acceso.
     * Utiliza caché para optimizar el rendimiento.
     */
    public function getSubscriptionModulePrefixes(): Collection
    {
        if ($this->roles()->exists()) {
            return collect(); // Esta función es solo para propietarios
        }

        // La clave del caché es única para cada usuario. Se almacena por 1 hora.
        return Cache::remember('user_' . $this->id . '_module_prefixes', 3600, function () {
            // Usamos la nueva relación `currentVersion` para obtener el plan activo.
            $currentVersion = $this->branch->subscription->currentVersion;

            if (! $currentVersion) {
                return collect();
            }

            // Obtiene las claves de los items (ej. 'module_pos') y las convierte en prefijos (ej. 'pos').
            return $currentVersion->items
                ->where('item_type', 'module')
                ->pluck('item_key')
                ->map(fn($key) => str_replace('module_', '', $key));
        });
    }

    /**
     * The bank accounts that the user has access to.
     */
    public function bankAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BankAccount::class);
    }

    /**
     * Novedades que el usuario ya ha leído.
     */
    public function readReleaseNotes(): BelongsToMany
    {
        return $this->belongsToMany(ReleaseNote::class, 'release_note_user')
                    ->withPivot('read_at', 'banner_dismissed_at');
    }

    /**
     * Obtiene la cantidad de novedades publicadas que el usuario AÚN NO ha leído.
     */
    public function unreadReleaseNotesCount(): int
    {
        $readIds = $this->readReleaseNotes()->pluck('release_notes.id');
        
        return ReleaseNote::published()
            ->whereNotIn('id', $readIds)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO DE INTERFAZ (REFACTOR MIDDLEWARE)
    |--------------------------------------------------------------------------
    */

    public function getPreferences(): array
    {
        $userSettings = $this->settings()
            ->with('definition:id,key')
            ->get()
            ->mapWithKeys(fn ($setting) => [$setting->definition->key => $setting->value])
            ->toArray();

        return array_merge([
            'default_table_click_action' => 'drawer',
        ], $userSettings);
    }

    public function getGlobalNotifications(): array
    {
        if ($this->roles()->exists() && !$this->can('transactions.access')) {
            return ['expiring_debts' => 0, 'upcoming_deliveries' => 0, 'unread_updates' => 0, 'total' => 0];
        }

        $branchId = $this->branch_id;
        
        $expiringDebts = Transaction::where('branch_id', $branchId)
            ->whereIn('status', [TransactionStatus::ON_LAYAWAY, TransactionStatus::PENDING])
            ->whereNotNull('layaway_expiration_date')
            ->whereDate('layaway_expiration_date', '<=', now()->addDays(3))
            ->count();

        $upcomingDeliveries = Transaction::where('branch_id', $branchId)
            ->where('status', TransactionStatus::TO_DELIVER)
            ->whereNotNull('delivery_date')
            ->whereDate('delivery_date', '<=', now()->addDays(3))
            ->count();

        // Obtenemos la cantidad de novedades sin leer
        $unreadUpdates = $this->unreadReleaseNotesCount();

        // Pedidos pendientes de la tienda en línea (solo si el módulo está activo)
        $pendingOrders = 0;
        if (in_array('Tienda en línea', $this->branch?->subscription?->getAvailableModuleNames() ?? [])) {
            $pendingOrders = \App\Models\Order::whereHas('storeConfig', fn($q) => $q->where('subscription_id', $this->branch?->subscription_id))
                ->whereIn('status', [\App\Enums\OrderStatus::Pending, \App\Enums\OrderStatus::Reviewed])
                ->count();
        }

        return [
            'expiring_debts' => $expiringDebts, 
            'upcoming_deliveries' => $upcomingDeliveries,
            'unread_updates' => $unreadUpdates,
            'pending_orders' => $pendingOrders,
            'total' => $expiringDebts + $upcomingDeliveries + $unreadUpdates + $pendingOrders,
        ];
    }

   /**
     * Verifica si el usuario tiene una sesión de caja abierta en la sucursal actual.
     */
    public function hasActiveCashRegisterSession(): bool
    {
        return $this->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $this->branch_id))
            ->exists();
    }

    public function getActiveCashRegisterSession()
    {
        return $this->cashRegisterSessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $this->branch_id))
            ->with(['users', 'cashMovements', 'payments.transaction:id,folio'])
            ->first();
    }

    public function getJoinableCashRegisterSessions()
    {
        if ($this->hasActiveCashRegisterSession()) {
            return collect();
        }

        return CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
            ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $this->branch_id))
            ->with('cashRegister:id,name', 'opener:id,name')
            ->get();
    }

    public function getAvailableCashRegisters()
    {
        if ($this->hasActiveCashRegisterSession()) {
            return collect();
        }

        return CashRegister::where('branch_id', $this->branch_id)
            ->where('is_active', true)
            ->where('in_use', false) 
            ->get(['id', 'name']);
    }

    /*
    |--------------------------------------------------------------------------
    | REFERRAL SYSTEM RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    public function referralCode(): HasOne
    {
        return $this->hasOne(ReferralCode::class);
    }

    public function referralUsagesAsReferrer(): HasManyThrough
    {
        return $this->hasManyThrough(ReferralUsage::class, ReferralCode::class, 'user_id', 'referral_code_id');
    }

    public function referrerBankAccount(): HasOne
    {
        return $this->hasOne(ReferrerBankAccount::class);
    }

    public function hasPendingReferralRewards(): bool
    {
        return $this->referralUsagesAsReferrer()
            ->where('reward_status', 'pending')
            ->exists();
    }

    public function getUnseenReferralsCount(): int
    {
        return $this->referralUsagesAsReferrer()
            ->whereNull('seen_at')
            ->count();
    }
}