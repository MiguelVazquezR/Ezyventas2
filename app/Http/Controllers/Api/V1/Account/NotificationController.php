<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\NotificationsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Counters of the bell icon of the topbar.
 */
class NotificationController extends Controller
{
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
        return response()->json(array_merge(
            self::EMPTY_COUNTERS,
            $request->user()->getGlobalNotifications()
        ));
    }
}
