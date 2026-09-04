<?php

namespace App\Services\Auth;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class EmailVerificationCodeService
{
    /**
     * How long (in seconds) the code stays valid.
     */
    public const TTL_SECONDS = 600; // 10 minutes

    /**
     * Maximum wrong attempts before the code is invalidated.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Minimum wait (in seconds) before a new code can be requested.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Length of the numeric code.
     */
    public const CODE_LENGTH = 6;

    /**
     * Generate a fresh code, e-mail it and store only its hash in cache.
     */
    public function send(User $user): void
    {
        $code = $this->generateCode();

        Mail::to($user->email)->send(new EmailVerificationCode($user, $code));

        Cache::put($this->cacheKey($user), [
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'sent_at' => now()->timestamp,
        ], self::TTL_SECONDS);
    }

    /**
     * Send a code only when there is no active one. Returns true when a new
     * code was e-mailed, false when a valid code is already pending.
     */
    public function ensureCode(User $user): bool
    {
        if ($this->getPayload($user) !== null) {
            return false;
        }

        $this->send($user);

        return true;
    }

    /**
     * Verify a submitted code. Returns one of: 'verified', 'invalid',
     * 'expired' (no active code) or 'blocked' (too many attempts).
     */
    public function verify(User $user, string $code): string
    {
        $payload = $this->getPayload($user);

        if ($payload === null) {
            return 'expired';
        }

        if (hash_equals((string) $payload['code_hash'], hash('sha256', $code))) {
            $this->forget($user);

            return 'verified';
        }

        $attempts = ($payload['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->forget($user);

            return 'blocked';
        }

        Cache::put($this->cacheKey($user), [
            'code_hash' => $payload['code_hash'],
            'attempts' => $attempts,
            'sent_at' => $payload['sent_at'],
        ], now()->addSeconds(self::TTL_SECONDS));

        return 'invalid';
    }

    /**
     * Seconds the user must still wait before requesting a new code.
     */
    public function secondsUntilResend(User $user): int
    {
        $payload = $this->getPayload($user);

        if ($payload === null) {
            return 0;
        }

        $elapsed = now()->timestamp - (int) ($payload['sent_at'] ?? now()->timestamp);

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Whether a new code can be requested right now.
     */
    public function canResend(User $user): bool
    {
        return $this->secondsUntilResend($user) === 0;
    }

    /**
     * Remove the active code from cache.
     */
    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    protected function generateCode(): string
    {
        return (string) random_int(10 ** (self::CODE_LENGTH - 1), 10 ** self::CODE_LENGTH - 1);
    }

    protected function cacheKey(User $user): string
    {
        return 'email_verification_code_' . $user->id;
    }

    protected function getPayload(User $user): ?array
    {
        return Cache::get($this->cacheKey($user));
    }
}
