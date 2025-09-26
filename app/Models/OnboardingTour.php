<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OnboardingTour extends Model
{
    use HasFactory;

    protected $table = 'onboarding_tours';

    protected $fillable = ['key', 'name', 'module'];

    public function completedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'onboarding_tour_user')
            ->withPivot('completed_at')
            ->withTimestamps();
    }
}
