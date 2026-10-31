<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;

/**
 * Registers a customer in the branch of the given user.
 *
 * New customers always start with a zero balance: debts and credits are born
 * from transactions, never from the creation form.
 */
class CreateCustomerAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, User $user): Customer
    {
        unset($data['client_uuid']);

        return Customer::create(array_merge($data, [
            'branch_id' => $user->branch_id,
            'balance' => 0,
            'address' => $data['address'] ?? [],
        ]));
    }
}
