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
            
            // Si no es propietario, devuelve null para que el gate continúe 
            // con las verificaciones de roles/permisos normales.
            return null;
        });
    }
}
