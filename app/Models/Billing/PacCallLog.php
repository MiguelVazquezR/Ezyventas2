<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PacCallLog
 *
 * Audit trail of every call to the PAC (stamp, cancel, upload_csd,
 * authenticate, balance).
 *
 * SECURITY: request_payload must NEVER contain the PAC account password
 * nor binary CSD/private-key content — only safe metadata.
 */
class PacCallLog extends Model
{
    use HasFactory;

    protected $table = 'pac_call_logs';

    protected $fillable = [
        'fiscal_profile_id',
        'pac_account_id',
        'operation',
        'customid',
        'request_payload',
        'response_status_code',
        'response_body',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'request_payload'      => 'array',
            'response_body'        => 'array',
            'response_status_code' => 'integer',
            'duration_ms'          => 'integer',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function pacAccount(): BelongsTo
    {
        return $this->belongsTo(PacAccount::class);
    }
}
