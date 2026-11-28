<?php

namespace App\Models;

use App\Traits\HasSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metric event of the "Modo Caja Inteligente" (Phase 2).
 *
 * Deliberately anemic: the model only declares the table, the casts, the
 * relations and the query scopes. Every business rule (which tenant the row
 * belongs to, whether the referenced sale is from the same branch) lives in
 * SmartCheckoutAnalyticsService.
 *
 * No images are stored: `objects`, `auto_accepted`, `confirmed`, `corrected` and
 * `unknown` are aggregate counters of one analysis.
 */
class SmartPosDetectionEvent extends Model
{
    use HasSubscription;

    /** Event type of one recognition cycle. */
    public const TYPE_ANALYSIS = 'analysis';

    /** Event type of one sale closed with the mode active. */
    public const TYPE_SALE = 'sale';

    protected $table = 'smartpos_detection_events';

    protected $fillable = [
        'subscription_id',
        'branch_id',
        'user_id',
        'session_id',
        'event_type',
        'objects',
        'auto_accepted',
        'confirmed',
        'corrected',
        'unknown',
        'latency_ms',
        'model_version',
        'sale_id',
        'duration_seconds',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'objects' => 'integer',
            'auto_accepted' => 'integer',
            'confirmed' => 'integer',
            'corrected' => 'integer',
            'unknown' => 'integer',
            'latency_ms' => 'integer',
            'duration_seconds' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sale closed with the mode active (null on analysis events and on sales
     * registered without a transaction id).
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'sale_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    /**
     * Events of one branch (the operation scope of the module).
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Events whose `occurred_at` falls inside the given range. Either bound may
     * be null, which leaves that side unbounded.
     */
    public function scopeBetweenDates(Builder $query, mixed $from, mixed $to): Builder
    {
        if ($from === null && $to === null) {
            return $query;
        }

        if ($from !== null && $to !== null) {
            return $query->whereBetween('occurred_at', [$from, $to]);
        }

        return $from !== null
            ? $query->where('occurred_at', '>=', $from)
            : $query->where('occurred_at', '<=', $to);
    }

    /**
     * Events of one type (`analysis` | `sale`).
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }
}
