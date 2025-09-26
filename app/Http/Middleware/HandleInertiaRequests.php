<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;
use Tightenco\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => function () use ($request) {
                $user = $request->user();

                if (!$user) {
                    return null;
                }

                $isOwner = !$user->roles()->exists();

                // Si es el propietario/admin, obtiene todos los permisos; de lo contrario, solo los suyos.
                $permissions = $isOwner
                    ? Permission::all()->pluck('name')
                    : $user->getAllPermissions()->pluck('name');

                $subscription = $user->branch->subscription;

                return [
                    'user' => $user,
                    'permissions' => $permissions,
                    'is_subscription_owner' => $isOwner,
                    'subscription' => [
                        'commercial_name' => $subscription->commercial_name,
                    ],
                    'current_branch' => $user->branch,
                    'available_branches' => $subscription->branches()->get(['id', 'name']),
                ];
            },
            // Mensajes flash para notificaciones (toasts).
            'flash' => function () use ($request) {
                return [
                    'success' => $request->session()->get('success'),
                    'error' => $request->session()->get('error'),
                    'warning' => $request->session()->get('warning'),
                    'info' => $request->session()->get('info'),
                ];
            },
        ]);
    }
}
