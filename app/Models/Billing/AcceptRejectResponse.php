<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcceptRejectResponse extends Model
{
    use HasFactory;

    protected $table = 'accept_reject_responses';

    protected $fillable = [
        'branch_id',
        'fiscal_profile_id',
        'rfc',
        'uuid',
        'action',
        'status',
        'acuse',
        'estatus_uuid',
        'respuesta',
        'message',
        'message_detail',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
