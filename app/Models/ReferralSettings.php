<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'referred_discount_pct',
        'referrer_reward_pct',
        'referrer_ongoing_discount_pct',
    ];
}
