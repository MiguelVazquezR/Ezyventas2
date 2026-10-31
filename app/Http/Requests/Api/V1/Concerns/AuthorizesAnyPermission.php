<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Lets a request allow more than one permission: the mobile app serves the POS
 * and the service orders module, so some reads are valid for either of them.
 */
trait AuthorizesAnyPermission
{
    /**
     * @param  array<int, string>  $permissions
     */
    protected function canAnyPermission(array $permissions): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
