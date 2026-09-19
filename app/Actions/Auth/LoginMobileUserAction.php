<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\SubscriptionAccessGuard;
use App\Services\Auth\UserAccessContextService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authenticates a mobile device and issues a Sanctum token.
 *
 * The returned payload is exactly what the app needs after signing in:
 * the token plus the full access context (branch, subscription, modules
 * and permissions).
 */
class LoginMobileUserAction
{
    public function __construct(
        private readonly UserAccessContextService $accessContext,
        private readonly SubscriptionAccessGuard $accessGuard,
    ) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array<string, mixed>
     */
    public function execute(array $credentials, string $deviceName): array
    {
        $user = User::query()
            ->with('branch.subscription')
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $this->accessGuard->ensureUserCanOperate($user);

        $token = $user->createToken($deviceName, ['mobile'])->plainTextToken;

        return array_merge(
            [
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            $this->accessContext->build($user)
        );
    }
}
