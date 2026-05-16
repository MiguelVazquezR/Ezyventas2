<?php

namespace App\Actions\Quote;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreQuoteAction
{
    public function execute(array $data, User $user): Quote
    {
        return DB::transaction(function () use ($data, $user) {
            
            $quote = Quote::create(array_merge($data, [
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'folio' => Quote::generateFolio($user->branch_id),
                'status' => QuoteStatus::DRAFT,
            ]));

            $quote->syncItems($data['items'] ?? []);

            return $quote;
        });
    }
}