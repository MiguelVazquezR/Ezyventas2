<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\NotificationsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Counters of the bell icon of the topbar.
 */
class NotificationController extends Controller
{
    /** Name the plan items use for the online store module. */
    private const ONLINE_STORE_MODULE = 'Tienda en línea';

    /**
     * Every counter is always present, even when the user has no access to the
     * module (then they all come as zero).
     */
    private const EMPTY_COUNTERS = [
        'expiring_debts' => 0,
        'upcoming_deliveries' => 0,
        'unread_updates' => 0,
        'pending_orders' => 0,
        'total' => 0,
    ];

    public function index(NotificationsRequest $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(array_merge(
            self::EMPTY_COUNTERS,
            $user->getGlobalNotifications(),
            [
                // Which modules the business has contracted: the app hides the
                // counters that do not apply instead of showing a zero it cannot
                // explain (the online store orders are managed from the web).
                'modules' => [
                    'online_store' => $this->hasOnlineStore($user),
                ],
            ]
        ));
    }

    private function hasOnlineStore(User $user): bool
    {
        $modules = $user->branch?->subscription?->getAvailableModuleNames() ?? [];

        return in_array(self::ONLINE_STORE_MODULE, $modules, true);
    }
}
