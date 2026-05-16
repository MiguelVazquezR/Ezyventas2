<?php

namespace App\Actions\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StoreUserAction
{
    public function execute(array $data, int $branchId): User
    {
        return DB::transaction(function () use ($data, $branchId) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'branch_id' => $branchId,
                'email_verified_at' => now(),
            ]);

            $role = Role::find($data['role_id']);
            if ($role) {
                $user->assignRole($role);
            }

            if (!empty($data['bank_account_ids'])) {
                $user->bankAccounts()->sync($data['bank_account_ids']);
            }

            return $user;
        });
    }
}