<?php

namespace App\Console\Commands;

use App\Services\Billing\StampMovementService;
use Illuminate\Console\Command;

class BackfillStampMovements extends Command
{
    protected $signature = 'stamp-movements:backfill
        {--profile= : Backfill only a specific fiscal profile ID}';

    protected $description = 'Backfill historical stamp movements from StampPurchase and Invoice records';

    public function handle(StampMovementService $service): int
    {
        $profileId = $this->option('profile');

        if ($profileId) {
            $profile = \App\Models\Billing\FiscalProfile::find($profileId);

            if (! $profile) {
                $this->error("Fiscal profile #{$profileId} not found.");
                return self::FAILURE;
            }

            $count = $service->backfillForProfile($profile);
            $this->info("Created {$count} movements for fiscal profile #{$profileId}.");
        } else {
            $count = $service->backfillAll();
            $this->info("Created {$count} movements across all fiscal profiles.");
        }

        return self::SUCCESS;
    }
}
