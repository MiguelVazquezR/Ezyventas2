<?php

namespace App\Actions\Invoices;

use App\Models\BillingSetting;

class SaveBillingSettingsAction
{
    /**
     * Create or update the billing settings for the current branch (upsert).
     *
     * Since each branch has at most one BillingSetting record
     * (unique constraint on branch_id), this action performs an
     * upsert — creating the record if it does not exist, or updating
     * it in place otherwise.
     */
    public function execute(array $data, int $branchId): BillingSetting
    {
        return BillingSetting::updateOrCreate(
            ['branch_id' => $branchId],
            [
                'emitter_rfc'          => $data['emitter_rfc'],
                'emitter_legal_name'   => $data['emitter_legal_name'],
                'emitter_tax_regime'   => $data['emitter_tax_regime'],
                'emitter_postal_code'  => $data['emitter_postal_code'],
            ],
        );
    }
}
