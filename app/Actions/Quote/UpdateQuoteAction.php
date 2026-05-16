<?php

namespace App\Actions\Quote;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateQuoteAction
{
    public function execute(Quote $quote, array $data, User $user): Quote
    {
        return DB::transaction(function () use ($quote, $data, $user) {
            
            $quote->update($data);

            if (count($data['items'] ?? []) > 0 || $quote->items()->count() > 0) {
                 activity()
                    ->performedOn($quote)
                    ->causedBy($user)
                    ->event('updated')
                    ->log('Se actualizaron los conceptos de la cotización.');
            }

            $quote->syncItems($data['items'] ?? []);

            return $quote;
        });
    }
}