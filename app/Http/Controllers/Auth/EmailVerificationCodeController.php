<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeEmailRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Services\Auth\EmailVerificationCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationCodeController extends Controller
{
    public function __construct(
        private readonly EmailVerificationCodeService $emailVerificationCodeService,
    ) {}

    /**
     * Show the "enter the code we e-mailed you" screen. Replaces Fortify's
     * default verification-link prompt with the OTP-code flow.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->redirectVerified();
        }

        // If there is no active code (expired, never sent), send a fresh one.
        $justSent = $this->emailVerificationCodeService->ensureCode($user);

        return Inertia::render('Auth/VerifyEmailCode', [
            // Full address on purpose: so the user can notice a typo before
            // verifying and correct it with the "cambiar correo" action.
            'email' => $user->email,
            'justSent' => $justSent || session('status') === 'code-sent',
            'resendIn' => $this->emailVerificationCodeService->secondsUntilResend($user),
        ]);
    }

    /**
     * Validate the code sent to the user's e-mail and, when correct, mark the
     * e-mail as verified so the user can enter the system.
     */
    public function verify(VerifyEmailCodeRequest $request): RedirectResponse
    {
        $user = $request->user();
        $result = $this->emailVerificationCodeService->verify($user, $request->validated()['code']);

        if ($result === 'verified') {
            $user->markEmailAsVerified();

            return $this->redirectVerified();
        }

        throw ValidationException::withMessages([
            'code' => $this->errorMessageFor($result),
        ]);
    }

    /**
     * Re-send a fresh verification code to the user's e-mail.
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->redirectVerified();
        }

        if (! $this->emailVerificationCodeService->canResend($user)) {
            throw ValidationException::withMessages([
                'code' => 'Espera unos segundos antes de solicitar otro código.',
            ]);
        }

        $this->emailVerificationCodeService->send($user);

        return back()->with('status', 'code-sent');
    }

    /**
     * Let a pending (unverified) user fix a typo in their e-mail address.
     * Updates the address, keeps the subscription contact in sync and sends a
     * fresh code to the corrected inbox.
     */
    public function changeEmail(ChangeEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $newEmail = Str::lower($request->validated()['email']);

        if ($newEmail === $user->email) {
            return redirect()->route('verification.notice');
        }

        $oldEmail = $user->email;

        $user->update(['email' => $newEmail]);

        $subscription = $user->branch?->subscription;
        if ($subscription && $subscription->contact_email === $oldEmail) {
            $subscription->update(['contact_email' => $newEmail]);
        }

        $this->emailVerificationCodeService->send($user);

        return redirect()->route('verification.notice')->with('status', 'code-sent');
    }

    protected function redirectVerified(): RedirectResponse
    {
        return redirect()->intended(config('fortify.home', '/dashboard'));
    }

    protected function errorMessageFor(string $result): string
    {
        return match ($result) {
            'invalid' => 'El código no es correcto. Revísalo e inténtalo de nuevo.',
            'expired' => 'Este código ya expiró. Solicita uno nuevo.',
            'blocked' => 'Demasiados intentos fallidos. Solicita un código nuevo.',
            default => 'No se pudo verificar el código. Inténtalo de nuevo.',
        };
    }
}
