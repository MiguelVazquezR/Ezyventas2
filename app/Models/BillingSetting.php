<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSetting extends Model
{
    use HasFactory;

    protected $table = 'billing_settings';

    protected $fillable = [
        'branch_id',
        'emitter_rfc',
        'emitter_legal_name',
        'emitter_tax_regime',
        'emitter_postal_code',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the branch has its fiscal settings configured for CFDI stamping.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->emitter_rfc)
            && ! empty($this->emitter_legal_name)
            && ! empty($this->emitter_tax_regime)
            && ! empty($this->emitter_postal_code);
    }
}
