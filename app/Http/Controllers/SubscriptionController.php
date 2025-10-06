<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\PlanItem;
use App\Models\SubscriptionPayment;
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

        if ($user->roles()->exists()) {
            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        $subscription = $user->branch->subscription()->with([
            'branches',
            'bankAccounts.branches:id,name', // Carga las cuentas y las sucursales a las que están asignadas
            'versions' => function ($query) {
                $query->with(['items', 'payments'])->latest('start_date');
            },
            'media'
        ])->firstOrFail();

        $planItems = PlanItem::where('is_active', true)->get();

        return Inertia::render('Subscription/Show', [
            'subscription' => $subscription,
            'planItems' => $planItems,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $validated = $request->validate([
            'commercial_name' => 'required|string|max:255',
            'business_name' => 'nullable|string|max:255',
        ]);

        $user->branch->subscription->update($validated);
        return redirect()->back()->with('success', 'Información actualizada con éxito.');
    }

    public function requestInvoice(SubscriptionPayment $payment)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        // Validar que el pago pertenece a la suscripción del usuario
        if ($payment->subscriptionVersion->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        if ($payment->invoice_status === InvoiceStatus::NOT_REQUESTED) {
            $payment->update(['invoice_status' => InvoiceStatus::REQUESTED]);
            return redirect()->back()->with('success', 'Factura solicitada. Nos pondremos en contacto pronto.');
        }

        return redirect()->back()->with('info', 'Esta factura ya ha sido solicitada o generada.');
    }

    /**
     * Almacena el documento fiscal de la suscripción.
     */
    public function storeDocument(Request $request)
    {
        $request->validate([
            'fiscal_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ]);

        $user = Auth::user();
        if ($user->roles()->exists()) {
            abort(403);
        }

        $subscription = $user->branch->subscription;

        // Limpia la colección antes de añadir el nuevo archivo para asegurar que solo haya uno.
        $subscription->clearMediaCollection('fiscal-documents');

        $subscription->addMediaFromRequest('fiscal_document')
            ->toMediaCollection('fiscal-documents');

        return redirect()->back()->with('success', 'Documento fiscal actualizado con éxito.');
    }
}
