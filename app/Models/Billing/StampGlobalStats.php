<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * StampGlobalStats
 *
 * Cached snapshot of aggregated stamp statistics across all
 * active fiscal profiles. Computed periodically by a scheduled
 * job and refreshable on demand from the Global Panel.
 *
 * This is a single-row table — the latest snapshot is always row ID 1.
 */
class StampGlobalStats extends Model
{
    protected $table = 'stamp_global_stats_snapshots';

    protected $fillable = [
        'total_stamps_assigned',
        'total_stamps_used',
        'active_issuers_count',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_stamps_assigned' => 'integer',
            'total_stamps_used'     => 'integer',
            'active_issuers_count'  => 'integer',
            'computed_at'           => 'datetime',
        ];
    }

    /**
     * Get the latest (and only relevant) snapshot.
     */
    public static function latest(): ?self
    {
        return self::orderByDesc('id')->first();
    }
}
