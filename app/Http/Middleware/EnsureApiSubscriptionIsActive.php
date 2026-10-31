<?php

namespace App\Http\Middleware;

use App\Services\Auth\SubscriptionAccessGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated users and employees whose subscription is no longer
 * active (expired or suspended).
 *
 * Mirrors the web middleware CheckSubscriptionStatus, but answers with JSON
 * because the mobile API never renders Inertia pages.
 */
class EnsureApiSubscriptionIsActive
{
    public function __construct(private readonly SubscriptionAccessGuard $accessGuard) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $this->accessGuard->ensureUserCanOperate($user);
        }

        return $next($request);
    }
}
