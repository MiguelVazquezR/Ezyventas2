<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
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
