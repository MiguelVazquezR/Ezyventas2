<?php

namespace App\Actions\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateUserAction
{
    public function execute(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $updateData = [
                'name' => $data['name'],
                'email' => $data['email'],
            ];

            if (!empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $user->update($updateData);

            $role = Role::find($data['role_id']);
            if ($role) {
                $user->syncRoles($role);
            }

            $user->bankAccounts()->sync($data['bank_account_ids'] ?? []);

            return $user;
        });
    }
}