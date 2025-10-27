<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckOnboardingStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        // 1. Si el usuario está logueado Y no ha completado el onboarding
        if ($user && !$user->subscription->onboarding_completed_at) {
            
            // 2. Y NO está ya en la página de onboarding o deslogueándose
            if (!$request->routeIs('onboarding.*') && !$request->routeIs('logout')) {
                
                // 3. Redirigir a la página de onboarding
                return redirect()->route('onboarding.setup');
            }
        }
        
        // 4. Si no, continuar normalmente
        return $next($request);
    }
}