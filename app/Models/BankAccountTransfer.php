<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccountTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'folio',
        'subscription_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'notes',
        'transfer_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'datetime',
    ];

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id');
    }
}