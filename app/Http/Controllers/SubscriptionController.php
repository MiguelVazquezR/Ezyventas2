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

        // MODIFICADO: Se añade `withCount` para obtener el número de recursos utilizados.
        // Asegúrate de que tu modelo `Subscription` tenga las relaciones correctas
        // (p. ej., users(), products(), etc.) para que withCount funcione.
        $subscription = $user->branch->subscription()->with([
            'branches',
            'bankAccounts.branches:id,name',
            'versions' => function ($query) {
                $query->with(['items', 'payments'])->latest('start_date');
            },
            'media'
        ])->withCount([
            'branches',
            'users',
            'bankAccounts',
            'products',
            'cashRegisters',
            'printTemplates',
        ])->firstOrFail();

        $planItems = PlanItem::where('is_active', true)->get();

        // NUEVO: Se crea un array con los datos de uso para pasarlo como prop.
        // Las claves deben coincidir con la parte variable de la `item_key` de tus límites.
        // Por ejemplo, si tu `item_key` es 'limit_branches', la clave aquí debe ser 'branches'.
        $usageData = [
            'branches' => $subscription->branches_count,
            'users' => $subscription->users_count,
            'bank_accounts' => $subscription->bank_accounts_count,
            'products' => $subscription->products_count,
            'cash_registers' => $subscription->cash_registers_count,
            'print_templates' => $subscription->print_templates_count,
        ];

        return Inertia::render('Subscription/Show', [
            'subscription' => $subscription,
            'planItems' => $planItems,
            'usageData' => $usageData, // NUEVO: Se pasa el contador de uso a la vista.
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