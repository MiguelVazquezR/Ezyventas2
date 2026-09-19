<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\SupportRequest;
use Illuminate\Http\JsonResponse;

/**
 * Support centre content: the app shows the same channels and help topics as the
 * web modal, served from config/support.php so they can change without shipping
 * a new app version.
 */
class SupportController extends Controller
{
    public function index(SupportRequest $request): JsonResponse
    {
        return response()->json(array_merge(config('support'), [
            'help_center_url' => rtrim((string) config('app.url'), '/') . '/centro-ayuda',
        ]));
    }
}
