<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginMobileUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Services\Auth\UserAccessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Token based authentication for the mobile app (Sanctum).
 */
class AuthController extends Controller
{
    public function __construct(private readonly UserAccessContextService $accessContext) {}

    /**
     * Authenticates the device and returns the access context.
     */
    public function login(LoginRequest $request, LoginMobileUserAction $action): JsonResponse
    {
        $payload = $action->execute(
            $request->safe()->only(['email', 'password']),
            $request->deviceName()
        );

        return response()->json($payload);
    }

    /**
     * Refreshes the access context of the authenticated device.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->accessContext->build($request->user()));
    }

    /**
     * Revokes the token used by this request.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
