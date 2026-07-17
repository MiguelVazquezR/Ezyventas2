<?php

namespace App\Providers;

use App\AiTools\EzyVentasToolProvider;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiToolProvider::class, EzyVentasToolProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Gate::before(function ($user, $ability) {
            // Superadmin (ID 1) tiene acceso irrestricto para fines de soporte
            // sin comprometer la lógica de protección de otras suscripciones.
            if ($user && $user->id === 1) {
                return true;
            }

            // Si el usuario es propietario (sin roles), verifica si tiene acceso
            // al permiso según los módulos de su suscripción.
            if ($user && !$user->roles()->exists()) {
                $subscription = $user->branch->subscription;
                $availableModuleNames = $subscription->getAvailableModuleNames();

                // Comprueba si el permiso solicitado ($ability) existe dentro de los módulos del plan o del sistema.
                // Si existe, devuelve true para autorizar. Si no, devuelve false para denegar.
                return Permission::query()
                    ->where('name', $ability)
                    ->where(function ($query) use ($availableModuleNames) {
                        $query->whereIn('module', $availableModuleNames)
                              ->orWhere('module', 'Sistema');
                    })
                    ->exists() ? true : null;
            }
            
            // Si no es propietario, también verificar que el permiso pertenezca
            // a un módulo activo de la suscripción.
            if ($user && $user->roles()->exists()) {
                $subscription = $user->subscription;
                $availableModuleNames = $subscription->getAvailableModuleNames();

                $permission = Permission::query()
                    ->where('name', $ability)
                    ->first();

                if ($permission && !in_array($permission->module, $availableModuleNames) && $permission->module !== 'Sistema') {
                    return false;
                }
            }

            return null;
        });
    }
}
