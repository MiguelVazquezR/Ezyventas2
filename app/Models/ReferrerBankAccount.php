<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferrerBankAccount extends Model
{
    use HasFactory;

    protected $fillable = ['subscription_id', 'clabe', 'bank_name', 'account_holder_name'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
