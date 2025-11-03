<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscriptionStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Si no hay usuario (login, etc.) o si es Propietario,
        // no hacemos nada aquí. El Propietario se sigue
        // validando en AppServiceProvider.
        if (!$user || !$user->roles()->exists() || $request->routeIs('logout') || $request->routeIs('dashboard')) {
            return $next($request);
        }

        // 2. Si llegamos aquí, es un Empleado (tiene roles).
        // Verificamos la suscripción.
        $subscription = $user->subscription;
        $currentVersion = $subscription->currentVersion();

        // 3. Si NO hay versión activa (expirada), bloqueamos.
        if (!$currentVersion) {
            abort(403, 'La suscripción de este negocio ha expirado.');
        }

        // 4. Si la suscripción está activa, lo dejamos pasar
        // al siguiente middleware (que será el 'can:pos.access' de Spatie).
        return $next($request);
    }
}
