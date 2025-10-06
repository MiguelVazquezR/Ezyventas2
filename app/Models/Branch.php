<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
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
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * Obtiene las plantillas de impresión asignadas a esta sucursal.
     */
    public function printTemplates(): BelongsToMany
    {
        return $this->belongsToMany(PrintTemplate::class, 'branch_print_template');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Obtiene las cuentas bancarias asignadas a esta sucursal.
     */
    public function bankAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BankAccount::class, 'bank_account_branch');
    }

    /**
     * Get the user who manages the branch.
     */
    // public function manager(): BelongsTo
    // {
    //     return $this->belongsTo(User::class, 'manager_id');
    // }

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
    
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all of the branch's settings.
     */
    public function settings(): MorphMany
    {
        // CAMBIO: Se actualiza el nombre de la relación a 'configurable' para que
        // coincida con el nuevo nombre del método en el modelo SettingValue.
        return $this->morphMany(SettingValue::class, 'configurable');
    }
}