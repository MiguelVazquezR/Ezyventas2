<?php

namespace App\Http\Middleware;

use App\Traits\HasSubscription;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures that every route-model-bound parameter belongs to the
 * authenticated user's subscription. If a model does not use the
 * HasSubscription trait, it is skipped.
 *
 * Aborts with 403 if a model is found to belong to a different subscription.
 */
class EnsureSubscriptionScope
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // No user = no subscription to check against (pass through)
        if (!$user || !$user->branch || !$user->branch->subscription_id) {
            return $next($request);
        }

        $userSubscriptionId = (int) $user->branch->subscription_id;

        foreach ($request->route()->parameters() as $parameterName => $value) {
            if (!($value instanceof Model)) {
                continue;
            }

            // Only check models that use the HasSubscription trait
            if (!in_array(HasSubscription::class, class_uses_recursive($value), true)) {
                continue;
            }

            $modelSubscriptionId = $value->getSubscriptionId();

            // If the model doesn't yield a subscription ID (e.g., not persisted),
            // or if it matches the user's subscription, allow it.
            if ($modelSubscriptionId === null || $modelSubscriptionId === $userSubscriptionId) {
                continue;
            }

            // Subscription mismatch — deny access
            abort(403, 'Acceso denegado. Este recurso no pertenece a tu suscripción.');
        }

        return $next($request);
    }
}
