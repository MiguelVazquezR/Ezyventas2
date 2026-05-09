<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Enums\SubscriptionPaymentStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Muestra el panel de métricas generales para el Superadmin.
     */
    public function index()
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();

        // 1. Ingresos (Solo pagos aprobados)
        $totalRevenue = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->sum('amount');

        $monthlyRevenue = SubscriptionPayment::where('status', SubscriptionPaymentStatus::APPROVED)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        // 2. Métrica de suscripciones activas
        // Se cuenta cruzando con las versiones que aún están vigentes al día de hoy
        $activeSubscriptionsCount = Subscription::whereHas('versions', function($q) {
            $q->where('end_date', '>=', now()->startOfDay());
        })->count();

        // 3. Nuevos clientes del mes actual
        $newSubscriptionsCount = Subscription::where('created_at', '>=', $startOfMonth)->count();

        return Inertia::render('Admin/Reports/Index', [
            'metrics' => [
                'totalRevenue' => (float) $totalRevenue,
                'monthlyRevenue' => (float) $monthlyRevenue,
                'activeSubscriptions' => $activeSubscriptionsCount,
                'newSubscriptions' => $newSubscriptionsCount,
            ]
        ]);
    }
}