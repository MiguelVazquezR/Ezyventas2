<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferrerBankAccount extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'clabe', 'bank_name', 'account_holder_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
