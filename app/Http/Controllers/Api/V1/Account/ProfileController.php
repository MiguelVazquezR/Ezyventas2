<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\LogoutOtherDevicesRequest;
use App\Http\Requests\Api\V1\Account\ProfileRequest;
use App\Http\Requests\Api\V1\Account\UpdateProfilePasswordRequest;
use App\Http\Requests\Api\V1\Account\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\EmailVerificationCodeService;
use App\Services\Auth\UserAccessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Profile of the authenticated user: personal data, photo, password and the
 * other active sessions.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly UserAccessContextService $accessContext,
        private readonly EmailVerificationCodeService $verificationCodes,
    ) {}

    public function show(ProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->profilePayload($user),
            'context' => $this->accessContext->build($user),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $emailChanged = $request->validated('email') !== $user->email;

        $user->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
        ]);

        // A new email has to be verified again with the code we send below.
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
        }

        if ($request->hasFile('photo')) {
            $user->updateProfilePhoto($request->file('photo'));
        }

        if ($emailChanged) {
            $this->verificationCodes->send($user->fresh());
        }

        return response()->json([
            'user' => $this->profilePayload($user->fresh()),
            'email_verification_sent' => $emailChanged,
            'message' => $emailChanged
                ? 'Tus datos se guardaron. Te enviamos un código de verificación a tu nuevo correo.'
                : 'Tus datos se guardaron.',
        ]);
    }

    public function destroyPhoto(ProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->deleteProfilePhoto();

        return response()->json([
            'user' => $this->profilePayload($user->fresh()),
            'message' => 'Foto eliminada.',
        ]);
    }

    public function updatePassword(UpdateProfilePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check((string) $request->validated('current_password'), (string) $user->password)) {
            return response()->json([
                'code' => 'invalid_current_password',
                'message' => 'La contraseña actual no es correcta.',
            ], 422);
        }

        $user->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Tu contraseña se actualizó.']);
    }

    public function logoutOtherDevices(LogoutOtherDevicesRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!Hash::check((string) $request->validated('password'), (string) $user->password)) {
            return response()->json([
                'code' => 'invalid_current_password',
                'message' => 'La contraseña actual no es correcta.',
            ], 422);
        }

        $currentTokenId = $user->currentAccessToken()?->id;

        // Every other device loses its token; the phone making the request keeps working.
        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return response()->json(['message' => 'Se cerraron las demás sesiones.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone' => $user->phone,
            'profile_photo_url' => $user->profile_photo_url,
            'has_photo' => !empty($user->profile_photo_path),
        ];
    }
}
