<?php

namespace App\Http\Controllers;

// Imports de Enums y Modelos
use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\BankAccount;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;

// Imports de Laravel
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /**
     * Muestra la página de detalles de la suscripción.
     */
    public function show(): Response
    {
        $user = Auth::user();

        if ($user->roles()->exists()) {
            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        $subscription = $user->branch->subscription()->with([
            'branches',
            'bankAccounts.branches:id,name',
            'versions' => function ($query) {
                // REGLA 3: Cargar todos los pagos (incluidos rechazados) para el historial
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

        // --- INICIO: Lógica para determinar el estado de la versión ---
        $currentVersion = $subscription->versions->first(); // Ya viene ordenada por latest('start_date')
        $isExpired = true;
        $daysUntilExpiry = null;
        $currentBillingPeriod = BillingPeriod::ANNUALLY; // Default

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);
            $isExpired = $endDate->isPast();

            if (!$isExpired) {
                $daysUntilExpiry = now()->startOfDay()->diffInDays($endDate->startOfDay(), false);
            } else {
                $daysUntilExpiry = 0; // O un valor que indique expiración
            }

            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }
        // --- FIN: Lógica de estado ---

        // --- INICIO: REGLA 2 - Lógica de Pago Rechazado ---
        // Obtener el ÚLTIMO pago registrado de la suscripción (independientemente de la versión)
        $lastPayment = $subscription->payments()
            ->latest('created_at')
            ->first();

        $lastRejectedPayment = null;
        // Si el último pago existe y su estado es REJECTED, lo pasamos a la vista.
        // Si el cliente vuelve a pagar (creando un pago 'pending') o se aprueba uno,
        // este ya no será el último y el mensaje desaparecerá.
        if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
            $lastRejectedPayment = $lastPayment;
        }
        // --- FIN: REGLA 2 ---

        $pendingPayment = null;
        $pendingPayment = $subscription->payments()
            ->where('status', SubscriptionPaymentStatus::PENDING)
            ->latest('created_at')
            ->first();

        $planItems = PlanItem::where('is_active', true)->get();

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
            'usageData' => $usageData,
            'subscriptionStatus' => [
                'isExpired' => $isExpired,
                'daysUntilExpiry' => $daysUntilExpiry,
                'currentBillingPeriod' => $currentBillingPeriod,
            ],
            'pendingPayment' => $pendingPayment,
            'lastRejectedPayment' => $lastRejectedPayment,
        ]);
    }

    /**
     * Actualiza la información básica de la suscripción.
     */
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

    /**
     * Permite al usuario solicitar una factura para un pago APROBADO.
     */
    public function requestInvoice(SubscriptionPayment $payment)
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        // REGLA 3: Solo permitir facturar pagos APROBADOS.
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

    /**
     * Almacena el documento fiscal (CSF) de la suscripción.
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

        // Limpia la colección para reemplazar el archivo
        $subscription->clearMediaCollection('fiscal-documents');

        $subscription->addMediaFromRequest('fiscal_document')
            ->toMediaCollection('fiscal-documents');

        return redirect()->back()->with('success', 'Documento fiscal actualizado con éxito.');
    }

    /**
     * Muestra la página inteligente para Mejorar o Renovar la suscripción.
     */
    public function manage(): Response
    {
        $user = Auth::user();
        if ($user->roles()->exists()) abort(403);

        $subscription = $user->branch->subscription;
        $allPlanItems = PlanItem::where('is_active', true)->get();

        // REGLA 3: Verificar si ya existe un pago pendiente
        $hasPendingPayment = $subscription->payments()
            ->where('status', SubscriptionPaymentStatus::PENDING)
            ->exists();

        $currentVersion = $subscription->versions()
            ->with('items')
            ->latest('start_date')
            ->first();

        $mode = 'renew';
        $currentBillingPeriod = BillingPeriod::ANNUALLY;

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);

            // Definimos "modo mejora" solo si falta MÁS de 5 días.
            // Si faltan 5 días o menos, ya se considera "modo renovación".
            if ($endDate->isFuture() && now()->diffInDays($endDate) > 5) {
                $mode = 'upgrade';
            }

            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }

        // Obtenemos las cuentas favoritas de la SUCURSAL 1 (la nuestra) para mostrarlas al cliente
        $ourBankAccounts = BankAccount::whereHas('branches', function ($query) {
            $query->where('branch_id', 1)->where('is_favorite', true);
        })->get();

        return Inertia::render('Subscription/ManageSubscription', [
            'subscription' => $subscription,
            'currentVersion' => $currentVersion,
            'allPlanItems' => $allPlanItems,
            'mode' => $mode, // 'upgrade' o 'renew'
            'currentBillingPeriod' => $currentBillingPeriod,
            'ourBankAccounts' => $ourBankAccounts, // Se envían nuestras cuentas bancarias
            'hasPendingPayment' => $hasPendingPayment, // Se informa a la vista si ya hay un pago en revisión
        ]);
    }

    /**
     * Procesa la solicitud de mejora o renovación.
     */
    public function processManagement(Request $request)
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
            'payment_method' => ['required', Rule::in(['transfer', 'stripe', 'card_mock'])],
            // El comprobante es requerido solo si el método es 'transfer'
            'proof_of_payment' => ['nullable', 'required_if:payment_method,transfer', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ]);

        $subscription = $user->branch->subscription;

        // REGLA 3: Bloquear si ya hay un pago pendiente de aprobación
        $existingPendingPayment = $subscription->payments()
            ->where('status', SubscriptionPaymentStatus::PENDING)
            ->exists();

        if ($existingPendingPayment) {
            return redirect()->back()->with('error', 'Ya tienes un pago pendiente de aprobación. Por favor, espera a que sea procesado.');
        }

        $allPlanItems = PlanItem::where('is_active', true)->get()->keyBy('key');

        Log::info("Iniciando pago de suscripción por {$validated['total_amount']} vía {$validated['payment_method']}");

        try {
            // Se delega la lógica al método correspondiente
            if ($validated['payment_method'] === 'transfer') {
                $this->handleTransferPayment($request, $subscription, $validated, $allPlanItems);
            } else {
                // Aquí iría la lógica de Stripe u otros métodos (card_mock)
                // $this->handleStripePayment(...)
                // $this->handleCardMockPayment(...)
            }
        } catch (\Exception $e) {
            Log::error("Error al procesar la suscripción: " . $e->getMessage());
            return redirect()->back()->with('error', 'Hubo un error al procesar tu pago. Por favor, intenta de nuevo.');
        }

        // Mensaje de éxito específico para transferencia
        if ($validated['payment_method'] === 'transfer') {
            return redirect()->route('subscription.show')->with('success', '¡Tu pago ha sido enviado! Está en revisión y se activará pronto.');
        }

        return redirect()->route('subscription.show')->with('success', '¡Tu suscripción ha sido actualizada con éxito!');
    }

    /**
     * Lógica para pagos por transferencia (pendientes de aprobación).
     */
    private function handleTransferPayment(Request $request, Subscription $subscription, array $validated, $allPlanItems)
    {
        DB::transaction(function () use ($request, $subscription, $validated, $allPlanItems) {
            $billingPeriod = BillingPeriod::from($validated['billing_period']);
            $mode = $validated['mode'];
            $startDate = null;
            $endDate = null;

            $currentVersion = $subscription->versions()->latest('start_date')->first();

            // --- LÓGICA DE FECHAS (ESTIMACIÓN) ---
            // El admin recalculará las fechas en la aprobación, esto es una estimación

            if ($mode === 'upgrade') {
                // REGLA 1: MODO MEJORA
                // La nueva versión empieza HOY y termina CUANDO LA ACTUAL TERMINABA.
                $startDate = now();
                $endDate = $currentVersion ? $currentVersion->end_date : now()->addMonth(); // Fallback por si no hay versión

                // Cortamos la versión anterior HOY
                if ($currentVersion) {
                    $currentVersion->update(['end_date' => now()]);
                }
            } else {
                // MODO RENOVACIÓN (REGLA 4)
                if ($currentVersion && $currentVersion->end_date->isFuture()) {
                    // Si paga ANTES, la nueva versión empieza cuando la actual TERMINA
                    $startDate = $currentVersion->end_date;
                } else {
                    // Si paga DESPUÉS (o es nueva), la nueva empieza HOY
                    $startDate = now();
                }
                $endDate = $billingPeriod === BillingPeriod::ANNUALLY
                    ? $startDate->copy()->addYear()
                    : $startDate->copy()->addMonth();
            }
            // --- FIN LÓGICA DE FECHAS ---

            // --- INICIO: REGLA 3 - Reutilización de Versión ---
            // Buscamos una versión "pendiente" (su último pago fue rechazado)
            $pendingVersion = $subscription->versions()
                ->whereHas('payments', function ($query) {
                    $query->where('status', SubscriptionPaymentStatus::REJECTED);
                })
                ->whereDoesntHave('payments', function ($query) {
                    // Asegurarse que no tenga ya un pago aprobado o pendiente
                    $query->whereIn('status', [SubscriptionPaymentStatus::APPROVED, SubscriptionPaymentStatus::PENDING]);
                })
                ->latest('start_date')
                ->first();

            $newVersion = $pendingVersion; // Reutilizar si se encuentra

            if (!$newVersion) {
                // Si no hay ninguna pendiente/rechazada, crear una nueva
                $newVersion = $subscription->versions()->create([
                    'start_date' => $startDate, // Fechas estimadas
                    'end_date' => $endDate,
                ]);
            } else {
                // Si la reutilizamos, actualizamos sus fechas estimadas y borramos items viejos
                $newVersion->update([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
                $newVersion->items()->delete(); // Borramos los items de la configuración rechazada anterior
            }
            // --- FIN: REGLA 3 ---

            // Insertar los nuevos items
            $subscriptionItems = [];
            foreach ($validated['items'] as $item) {
                $planItem = $allPlanItems->get($item['key']);
                if (!$planItem) continue;

                $unitPrice = $billingPeriod === BillingPeriod::ANNUALLY
                    ? $planItem->monthly_price * 10
                    : $planItem->monthly_price;

                $subscriptionItems[] = [
                    'subscription_version_id' => $newVersion->id,
                    'item_key' => $planItem->key,
                    'item_type' => $planItem->type,
                    'name' => $planItem->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'billing_period' => $billingPeriod,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('subscription_items')->insert($subscriptionItems);

            // REGLA 3: Creamos el NUEVO pago PENDIENTE para esta versión
            $payment = $newVersion->payments()->create([
                'amount' => $validated['total_amount'],
                'payment_method' => $validated['payment_method'],
                'status' => SubscriptionPaymentStatus::PENDING, // Directo a PENDING
                'invoice_status' => InvoiceStatus::NOT_REQUESTED,
            ]);

            // Adjuntar el comprobante directamente al PAGO
            if ($request->hasFile('proof_of_payment')) {
                $payment->addMediaFromRequest('proof_of_payment')
                    ->toMediaCollection('proof_of_payment');
            }

            // NO actualizamos el estado de la suscripción (status) hasta la aprobación del admin
        });
    }
}
