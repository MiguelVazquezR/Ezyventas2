<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Verificamos que el usuario esté autenticado,
        // que tenga una sucursal asignada (branch)
        // y que el ID de la suscripción de esa sucursal sea 1.
        if ($user && $user->branch && $user->branch->subscription_id === 1) {
            // Si cumple, dejamos que continúe.
            return $next($request);
        }

        // Si no cumple, denegamos el acceso con un error 403 (Prohibido).
        abort(403, 'No tienes permiso para acceder a esta sección.');
    }
}