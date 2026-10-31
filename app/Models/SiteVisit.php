<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SiteVisit extends Model
{
    protected $fillable = [
        'path',
        'route_name',
        'visitor_hash',
        'referrer',
        'device',
    ];

    /**
     * Restrict the query to visits recorded inside the given range.
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Count unique visitors (distinct anonymous fingerprints).
     */
    public function scopeUniqueVisitors(Builder $query): Builder
    {
        return $query->distinct();
    }
}
