<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /**
     * Muestra la página de detalles de la suscripción para el propietario.
     */
    public function show(): Response
    {
        $user = Auth::user();

        // Lógica de negocio: El propietario de la suscripción es el usuario
        // que no tiene roles asignados en una sucursal.
        if ($user->roles()->exists()) {
            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        $subscription = $user->branch->subscription()->with([
            'branches', // Carga todas las sucursales de la suscripción
            'versions' => function ($query) {
                $query->with(['items', 'payments'])->latest('start_date');
            } // Carga las versiones con sus items y pagos, las más recientes primero
        ])->firstOrFail();

        return Inertia::render('Subscription/Show', [
            'subscription' => $subscription,
        ]);
    }
}