<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\PlanItem;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use App\Enums\BillingPeriod;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus; // AÑADIDO
use App\Models\BankAccount; // AÑADIDO (Usamos tu modelo)
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

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
            'bankAccounts.branches:id,name',
            'versions' => function ($query) {
                // AÑADIDO: Cargar 'payments.media' para ver comprobantes rechazados
                $query->with(['items', 'payments.media'])->latest('start_date');
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

        
        $currentVersion = $subscription->versions->first();
        $isExpired = true;
        $daysUntilExpiry = null;
        $currentBillingPeriod = BillingPeriod::ANNUALLY;
        $pendingPayment = null; // AÑADIDO
        $rejectedPayment = null; // AÑADIDO

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);
            $isExpired = $endDate->isPast();
            
            if (!$isExpired) {
                 $daysUntilExpiry = now()->startOfDay()->diffInDays($endDate->startOfDay(), false);
            }
            
            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }

            // AÑADIDO: Buscar pagos pendientes o rechazados
            // Buscamos en TODOS los pagos de la suscripción, no solo en la versión actual,
            // por si una versión pendiente aún no es la "actual".
            $pendingPayment = $subscription->payments()
                ->where('status', SubscriptionPaymentStatus::PENDING)
                ->latest('created_at')
                ->first();
                
            $rejectedPayment = $subscription->payments()
                ->where('status', SubscriptionPaymentStatus::REJECTED)
                ->with('media') // Cargar el comprobante por si quiere verlo
                ->latest('created_at')
                ->first();
        }

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
            'pendingPayment' => $pendingPayment, // AÑADIDO
            'rejectedPayment' => $rejectedPayment, // AÑADIDO
        ]);
    }

    // ... (update, requestInvoice, storeDocument methods sin cambios) ...
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
            'fiscal_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ]);

        $user = Auth::user();
        if ($user->roles()->exists()) {
            abort(403);
        }

        $subscription = $user->branch->subscription;

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

        $currentVersion = $subscription->versions()
            ->with('items')
            ->latest('start_date')
            ->first();

        $mode = 'renew'; 
        $currentBillingPeriod = BillingPeriod::ANNUALLY; 

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);
            
            if ($endDate->isFuture() && now()->diffInDays($endDate) > 5) {
                $mode = 'upgrade'; 
            }

            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }
        
        // AÑADIDO: Obtener cuentas de la Sucursal 1 (Admin) que sean favoritas
        $adminBankAccounts = BankAccount::whereHas('branches', function ($query) {
            $query->where('branch_id', 1) // ID 1 = Tu sucursal admin
                  ->where('bank_account_branch.is_favorite', true); // Pivot table 'is_favorite'
        })->get();

        return Inertia::render('Subscription/ManageSubscription', [
            'subscription' => $subscription,
            'currentVersion' => $currentVersion, 
            'allPlanItems' => $allPlanItems,
            'mode' => $mode, // 'upgrade' o 'renew'
            'currentBillingPeriod' => $currentBillingPeriod,
            'adminBankAccounts' => $adminBankAccounts, // AÑADIDO (reemplaza 'companyBankAccounts')
        ]);
    }

    /**
     * Procesa la mejora o renovación de la suscripción.
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
            'payment_method' => 'required|string|in:card,transfer', // AÑADIDO
            // AÑADIDO: 'proof_of_payment' es requerido solo si el método es 'transfer'
            'proof_of_payment' => ['nullable', 'required_if:payment_method,transfer', 'file', 'mimes:pdf,jpg,jpeg,png'], 
        ]);

        // Lógica para Stripe (Futuro)
        if ($validated['payment_method'] === 'card') {
            Log::info("Intento de pago con tarjeta (Stripe) por {$validated['total_amount']}");
            // Por ahora, solo devolvemos un error
            return redirect()->back()->with('error', 'El pago con tarjeta no está disponible por el momento.');
        }

        // Lógica para Transferencia
        if ($validated['payment_method'] === 'transfer') {
            return $this->handleTransferPayment($request, $validated, $user);
        }
        
        return redirect()->back()->with('error', 'Método de pago no reconocido.');
    }

    /**
     * AÑADIDO: Lógica separada para manejar el pago por transferencia
     */
    private function handleTransferPayment(Request $request, array $validated, $user)
    {
        $subscription = $user->branch->subscription;
        $allPlanItems = PlanItem::where('is_active', true)->get()->keyBy('key');
        
        Log::info("Iniciando PAGO POR TRANSFERENCIA de suscripción por {$validated['total_amount']}");

        try {
            $newPayment = DB::transaction(function () use ($subscription, $validated, $allPlanItems, $request) {
                $billingPeriod = BillingPeriod::from($validated['billing_period']);
                $mode = $validated['mode'];
                $startDate = null;
                $endDate = null;

                $currentVersion = $subscription->versions()->latest('start_date')->first();
                
                // --- LÓGICA DE FECHAS (ESTIMADA) ---
                // La fecha final se calculará en la APROBACIÓN, pero guardamos una estimada.
                if ($mode === 'upgrade') {
                    // MODO MEJORA: La fecha de inicio es HOY (cuando se apruebe)
                    $startDate = now();
                    $endDate = $billingPeriod === BillingPeriod::ANNUALLY ? now()->addYear() : now()->addMonth();

                } else { // MODO RENOVACIÓN
                    // Si hay una versión actual y AÚN NO VENCE
                    if ($currentVersion && $currentVersion->end_date->isFuture()) {
                        // La nueva versión (estimada) empieza cuando la actual TERMINA
                        $startDate = $currentVersion->end_date;
                        $endDate = $billingPeriod === BillingPeriod::ANNUALLY 
                            ? $startDate->copy()->addYear() 
                            : $startDate->copy()->addMonth();
                    } else {
                        // Si no hay versión o YA VENCIÓ, la nueva (estimada) empieza HOY
                        $startDate = now();
                        $endDate = $billingPeriod === BillingPeriod::ANNUALLY ? now()->addYear() : now()->addMonth();
                    }
                }
                // --- FIN LÓGICA DE FECHAS (ESTIMADA) ---

                // Creamos la nueva versión (aún no está activa)
                $newVersion = $subscription->versions()->create([
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);

                $subscriptionItems = [];
                foreach ($validated['items'] as $item) {
                    $planItem = $allPlanItems->get($item['key']);
                    if (!$planItem) continue;

                    $unitPrice = $billingPeriod === BillingPeriod::ANNUALLY 
                        ? $planItem->monthly_price * 10 
                        : $planItem->monthly_price;
                    
                    if ($planItem->type === \App\Enums\PlanItemType::LIMIT && $planItem->meta['quantity'] > 0) {
                         // El precio unitario debe ser el costo del paquete.
                    }

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

                // Creamos el pago PENDIENTE
                $newPayment = $newVersion->payments()->create([
                    'amount' => $validated['total_amount'],
                    'payment_method' => 'transfer', 
                    'invoice_status' => InvoiceStatus::NOT_REQUESTED,
                    'status' => SubscriptionPaymentStatus::PENDING, // ESTADO PENDIENTE
                ]);
                
                // NO activamos la suscripción
                $subscription->update(['status' => SubscriptionStatus::EXPIRED]); // O dejarla como estaba
                
                // Adjuntamos el comprobante de pago al PAGO
                if ($request->hasFile('proof_of_payment')) {
                    $newPayment->addMediaFromRequest('proof_of_payment')
                        ->toMediaCollection('proof_of_payment');
                }
                
                return $newPayment;
            });
            
        } catch (\Exception $e) {
            Log::error("Error al procesar la transferencia de suscripción: " . $e->getMessage());
            return redirect()->back()->with('error', 'Hubo un error al procesar tu pago. Por favor, intenta de nuevo.');
        }

        return redirect()->route('subscription.show')->with('success', '¡Tu pago por transferencia ha sido recibido! Está en revisión y tu plan se activará pronto.');
    }
}