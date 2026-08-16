<?php

namespace App\Models\Billing;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceFolioCounter
 *
 * Per-(branch, series) atomic folio counter. Used by
 * SWSapienService::reserveNextFolio() inside a transaction to eliminate
 * the folio race condition (two concurrent creations computing the same folio).
 */
class InvoiceFolioCounter extends Model
{
    use HasFactory;

    protected $table = 'invoice_folio_counters';

    protected $fillable = [
        'branch_id',
        'series',
        'next_folio',
    ];

    protected function casts(): array
    {
        return [
            'next_folio' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
