<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'suscription_id',
        'name',
        'is_main',
        'contact_phone',
        'contact_email',
        'address',
        'manager_id',
        'timezone',
        'operating_hours',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'address' => 'array',
        'operating_hours' => 'array',
    ];

    /**
     * Get the subscription that owns the branch.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'suscription_id');
    }

    /**
     * Get the user who manages the branch.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get the transactions for the branch.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
    
    /**
     * Get the cash registers for the branch.
     */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    /**
     * Get all of the branch's settings.
     */
    public function settings(): MorphMany
    {
        // Esto permite a una sucursal tener configuraciones personalizadas [cite: 521, 523]
        return $this->morphMany(SettingValue::class, 'settable');
    }
}