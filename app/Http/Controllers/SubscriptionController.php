<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ApproveSubscriptionPaymentAction;
use App\Actions\Subscription\ProcessSubscriptionPaymentAction;
use App\Actions\Subscription\RevertFailedSubscriptionAction;
use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\PlatformMercadoPagoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function show(): Response
    {
        $user = Auth::user();

        if ($user->roles()->exists()) {
            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        $subscription = $user->branch->subscription()->with([
            'branches',
            'bankAccounts.branches:id,name',
            'versions' => fn($query) => $query->with(['items', 'payments'])->latest('id'),
            'media'
        ])->withCount([
            'branches', 'users', 'bankAccounts', 'products', 'cashRegisters', 'printTemplates', 'services',
        ])->firstOrFail();

        // REFACTOR: Toda la lógica de "Estado" y "Comparación" se movió a helpers del Modelo.
        $subscription->versions = $subscription->getVersionsWithComparison();

        return Inertia::render('Subscription/Show', [
            'subscription' => $subscription,
            'planItems' => PlanItem::where('is_active', true)->get(),
            'usageData' => [
                'branches' => $subscription->branches_count,
                'users' => $subscription->users_count,
                'bank_accounts' => $subscription->bank_accounts_count,
                'products' => $subscription->products_count,
                'cash_registers' => $subscription->cash_registers_count,
                'print_templates' => $subscription->print_templates_count,
                'services' => $subscription->services_count,
            ],
            'subscriptionStatus' => $subscription->getStatusData(),
            'pendingPayment' => $subscription->getPendingPayment(),
            'lastRejectedPayment' => $subscription->getLastRejectedPayment(),
            'fiscalDocumentUrl' => $subscription->getFirstMediaUrl('fiscal-documents') ?: null,
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $validated = $request->validate([
            'commercial_name' => 'required|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|array|size:7',
            'operating_hours.*.day' => 'required|string',
            'operating_hours.*.open' => 'required|boolean',
            'operating_hours.*.from' => 'nullable|date_format:H:i',
            'operating_hours.*.to' => 'nullable|date_format:H:i',
        ]);

        DB::transaction(function () use ($user, $validated) {
            $user->branch->subscription->update([
                'commercial_name' => $validated['commercial_name'],
                'business_name' => $validated['business_name'],
                'contact_phone' => $validated['contact_phone'],
                'address' => $validated['address'] ? ['text' => $validated['address']] : null,
            ]);

            if (isset($validated['operating_hours'])) {
                $user->branch->update(['operating_hours' => $validated['operating_hours']]);
            }
        });

        return redirect()->back()->with('success', 'Información actualizada con éxito.');
    }

    public function requestInvoice(SubscriptionPayment $payment)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        if ($payment->status !== SubscriptionPaymentStatus::APPROVED) {
            abort(403, 'Solo puedes solicitar facturas de pagos aprobados.');
        }

        if ($payment->subscriptionVersion->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        if ($payment->invoice_status === InvoiceStatus::NOT_REQUESTED) {
            $payment->update(['invoice_status' => InvoiceStatus::REQUESTED]);
            return redirect()->back()->with('success', 'Factura solicitada. Nos pondremos en contacto pronto.');
        }

        return redirect()->back()->with('info', 'Esta factura ya ha sido solicitada o generada.');
    }

    public function storeDocument(Request $request)
    {
        $request->validate([
            'fiscal_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $subscription = $user->branch->subscription;
        $subscription->clearMediaCollection('fiscal-documents');
        $subscription->addMediaFromRequest('fiscal_document')->toMediaCollection('fiscal-documents');

        return redirect()->back()->with('success', 'Documento fiscal actualizado con éxito.');
    }

    public function manage(): Response
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $subscription = $user->branch->subscription;
        $versionToDisplay = $subscription->versions()->with('items')->latest('id')->first();

        // Descuento acumulado por referidos activos (calculado dinámicamente)
        $referrerActiveDiscountPct = $subscription->getReferrerActiveDiscountPct();

        // Extraer lógica de configuración de versión y modo
        $managementData = $this->getManagementData($subscription, $versionToDisplay);

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = $isOwner ? $subscription->bankAccounts()->get(['bank_accounts.id', 'account_name', 'bank_name']) : $user->bankAccounts()->get(['bank_accounts.id', 'account_name', 'bank_name']);

        return Inertia::render('Subscription/ManageSubscription', array_merge($managementData, [
            'subscription' => $subscription,
            'currentVersion' => $versionToDisplay,
            'allPlanItems' => PlanItem::where('is_active', true)->get(),
            'hasPendingPayment' => (bool) $subscription->getPendingPayment(),
            'isFirstPayment' => $subscription->versions()->count() <= 1,
            'referrerActiveDiscountPct' => (float) $referrerActiveDiscountPct,
            'ourBankAccounts' => BankAccount::whereHas('branches', fn($q) => $q->where('branch_id', 1)->where('is_favorite', true))->get(),
            'userBankAccounts' => $userBankAccounts,
            'expenseCategories' => ExpenseCategory::where('subscription_id', $subscription->id)->get(['id', 'name']),
        ]));
    }

    public function revert(Request $request, RevertFailedSubscriptionAction $action)
    {
        try {
            $success = $action->execute($request->user()->branch->subscription);
            
            if ($success) {
                return redirect()->route('subscription.show')->with('success', 'Tu plan ha sido revertido a la versión anterior.');
            }
            return redirect()->back()->with('error', 'No se encontró una versión fallida para revertir.');

        } catch (\Exception $e) {
            Log::error("Error al revertir: " . $e->getMessage());
            return redirect()->back()->with('error', 'No se pudo revertir el plan. Intenta de nuevo.');
        }
    }

    public function processManagement(Request $request, ProcessSubscriptionPaymentAction $action)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $validated = $request->validate([
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'items' => 'required|array|min:1',
            'items.*.key' => 'required|string|exists:plan_items,key',
            'items.*.quantity' => 'required|integer|min:1',
            'total_amount' => 'required|numeric|min:0',
            'mode' => 'required|string|in:upgrade,renew',
            'payment_method' => ['required', Rule::in(['transferencia', 'mercadopago'])],
            'proof_of_payment' => ['nullable', 'required_if:payment_method,transferencia', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
            'bank_account_id' => 'nullable|numeric|exists:bank_accounts,id',
            'expense_category_id' => [Rule::requiredIf($request->bank_account_id != null)],
            'referral_code' => ['nullable', 'string', 'max:12'],
        ]);

        $subscription = $user->branch->subscription;

        if ($subscription->getPendingPayment()) {
            return redirect()->back()->with('error', 'Ya tienes un pago pendiente de aprobación. Por favor, espera a que sea procesado.');
        }

        try {
            // REFACTOR: El Controller delega todo el trabajo pesado al Action
            $payment = $action->execute($request, $subscription, $validated, PlanItem::where('is_active', true)->get()->keyBy('key'));
            
        } catch (\Exception $e) {
            Log::error("Error al procesar la suscripción: " . $e->getMessage());
            return redirect()->back()->with('error', 'Hubo un error al procesar tu pago. Por favor, intenta de nuevo.');
        }

        if ($validated['payment_method'] === 'mercadopago') {
            return redirect()->route('subscription.pay', ['payment' => $payment->id]);
        }

        $message = '¡Tu pago ha sido enviado! Está en revisión y se activará pronto.';

        return redirect()->route('subscription.show')->with('success', $message);
    }

    /**
     * Redirige al suscriptor al checkout de Mercado Pago para completar el pago.
     */
    public function pay(SubscriptionPayment $payment, PlatformMercadoPagoService $mpService): RedirectResponse|HttpResponse
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        if ($payment->subscriptionVersion->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        if ($payment->payment_method !== 'mercadopago' || $payment->status !== SubscriptionPaymentStatus::PENDING) {
            return redirect()->route('subscription.show')
                ->with('error', 'Este pago no se puede procesar con Mercado Pago.');
        }

        $subscription = $user->branch->subscription;
        $version = $payment->subscriptionVersion;
        $firstItem = $version->items->first();
        $billingPeriodLabel = $firstItem?->billing_period === BillingPeriod::ANNUALLY ? 'Plan anual' : 'Plan mensual';

        try {
            $preference = $mpService->createPreference([
                'items' => [[
                    'id'          => "sub-{$payment->id}",
                    'title'       => "Suscripción {$subscription->commercial_name}",
                    'description' => "{$billingPeriodLabel} EzyVentas",
                    'quantity'    => 1,
                    'unit_price'  => (float) $payment->amount,
                ]],
                'payer_email'             => $subscription->contact_email ?? $user->email,
                'subscription_payment_id' => $payment->id,
                'description'             => "Suscripción {$subscription->commercial_name}",
                'success_url'             => route('subscription.payment.return', ['payment' => $payment->id, 'status' => 'success']),
                'failure_url'             => route('subscription.payment.return', ['payment' => $payment->id, 'status' => 'failure']),
                'pending_url'             => route('subscription.payment.return', ['payment' => $payment->id, 'status' => 'pending']),
                'webhook_url'             => route('webhooks.mercadopago'),
            ]);

            // Guardar datos de la preferencia de MP en el pago
            $payment->update([
                'payment_details' => [
                    'mp_preference_id' => $preference['id'],
                    'mp_init_point'    => $preference['init_point'],
                ],
            ]);

            return Inertia::location($preference['init_point']);
        } catch (\Exception $e) {
            Log::error('MP subscription preference failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            return redirect()->route('subscription.show')
                ->with('error', 'No se pudo iniciar el pago con Mercado Pago. Intenta con transferencia bancaria.');
        }
    }

    /**
     * Recibe el retorno de Mercado Pago después del intento de pago.
     */
    public function paymentReturn(SubscriptionPayment $payment, Request $request, PlatformMercadoPagoService $mpService, ApproveSubscriptionPaymentAction $approveAction): RedirectResponse
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        if ($payment->subscriptionVersion->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        // Si ya está aprobado, no hacer nada
        if ($payment->status === SubscriptionPaymentStatus::APPROVED) {
            return redirect()->route('subscription.show')
                ->with('success', '¡Tu pago ya fue aprobado! Tu suscripción está activa.');
        }

        $status = $request->query('status');
        $mpPaymentId = $request->query('payment_id');

        // Si Mercado Pago reporta éxito, verificamos contra su API
        if ($status === 'success' && $mpPaymentId) {
            try {
                $mpPayment = $mpService->getPayment($mpPaymentId);

                if (($mpPayment['status'] ?? '') === 'approved') {
                    // Guardar datos del pago de MP antes de aprobar
                    $currentDetails = $payment->payment_details ?? [];
                    $currentDetails['mp_payment_id'] = $mpPaymentId;
                    $currentDetails['mp_status'] = $mpPayment['status'];
                    $payment->update(['payment_details' => $currentDetails]);

                    $approveAction->execute($payment);

                    return redirect()->route('subscription.show')
                        ->with('success', '¡Pago aprobado! Tu suscripción ha sido activada.');
                }

                Log::warning('MP payment return not approved', [
                    'payment_id'     => $payment->id,
                    'mp_payment_id'  => $mpPaymentId,
                    'mp_status'      => $mpPayment['status'] ?? 'unknown',
                ]);
            } catch (\Exception $e) {
                Log::error('MP payment verification failed', [
                    'payment_id'    => $payment->id,
                    'mp_payment_id' => $mpPaymentId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        if ($status === 'failure' || $status === 'pending') {
            return redirect()->route('subscription.show')
                ->with('warning', 'El pago no se completó. Puedes intentarlo de nuevo desde tu panel de suscripción.');
        }

        return redirect()->route('subscription.show')
            ->with('warning', 'No se pudo verificar tu pago. Si realizaste el pago, contáctanos para verificarlo manualmente.');
    }

    /**
     * Helper privado para limpiar la lógica visual del método manage().
     */
    private function getManagementData(Subscription $subscription, $versionToDisplay): array
    {
        $previousVersion = null;
        $isRetry = false;
        $versionForLogic = $versionToDisplay;

        if ($versionToDisplay) {
            $lastPayment = $versionToDisplay->payments()->latest('id')->first();
            $isRetry = $lastPayment?->status === SubscriptionPaymentStatus::REJECTED;

            if ($isRetry) {
                $previousVersion = $subscription->versions()->where('id', '!=', $versionToDisplay->id)->latest('id')->first();
                $versionForLogic = $previousVersion;
            }
        }

        $mode = 'renew';
        $currentBillingPeriod = BillingPeriod::ANNUALLY;

        if ($isRetry) {
            $mode = 'upgrade';
        } elseif ($versionForLogic && Carbon::parse($versionForLogic->end_date)->isFuture() && now()->diffInDays(Carbon::parse($versionForLogic->end_date)) > 5) {
            $mode = 'upgrade';
        }

        if ($versionForLogic && $firstItem = $versionForLogic->items->first()) {
            $currentBillingPeriod = $firstItem->billing_period ?? BillingPeriod::ANNUALLY;
        }

        return [
            'previousVersion' => $previousVersion,
            'isRetry' => $isRetry,
            'mode' => $mode,
            'currentBillingPeriod' => $currentBillingPeriod,
        ];
    }
}