<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\PlanItem;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
// AÑADIDO
use App\Models\Subscription;
use App\Enums\BillingPeriod;
use App\Models\SubscriptionVersion;
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
        $daysUntilExpiry = null; // NUEVO: Inicializar días restantes
        $currentBillingPeriod = BillingPeriod::ANNUALLY; // Default

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);
            $isExpired = $endDate->isPast();
            
            // NUEVO: Calcular días restantes. 
            // `diffInDays` sin `false` da un valor absoluto. 
            // Usamos `diffInDays(..., false)` para obtener un número negativo si ya pasó.
            // Solo nos importa si es futuro, así que podemos simplemente calcular la diferencia.
            // Si $endDate es hoy, diffInDays(now()) dará 0.
            $daysUntilExpiry = now()->diffInDays($endDate, false);

            // Si no ha expirado, $daysUntilExpiry será positivo o 0.
            // Si ya expiró, será negativo.
            // Para la lógica del frontend, es más fácil si solo contamos días positivos.
            if (!$isExpired) {
                 // Usamos ceil para redondear. Si faltan 2.5 días, cuenta como 3.
                 // O mejor, usamos `diffInDays` simple, que trunca. 
                 // Si falta 1 día y 23 horas, es 1 día.
                 $daysUntilExpiry = now()->startOfDay()->diffInDays($endDate->startOfDay(), false);
            } else {
                // Si está expirado, lo dejamos en un valor que indique expiración, ej -1 o 0.
                // $daysUntilExpiry ya será negativo o 0 si es hoy pero pasado.
            }

            
            // Obtener el periodo de facturación de la versión actual (asumimos que todos los items lo comparten)
            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }
        // --- FIN: Lógica de estado ---


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
                'daysUntilExpiry' => $daysUntilExpiry, // NUEVO: Enviar días restantes
                'currentBillingPeriod' => $currentBillingPeriod,
            ],
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

        $subscription->clearMediaCollection('fiscal-documents');

        $subscription->addMediaFromRequest('fiscal_document')
            ->toMediaCollection('fiscal-documents');

        return redirect()->back()->with('success', 'Documento fiscal actualizado con éxito.');
    }

    // --- MÉTODOS NUEVOS ---

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
            
            // MODIFICADO: Definimos "modo mejora" solo si falta MÁS de 5 días.
            // Si faltan 5 días o menos, ya se considera "modo renovación".
            if ($endDate->isFuture() && now()->diffInDays($endDate) > 5) {
                $mode = 'upgrade'; 
            }

            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }
        
        return Inertia::render('Subscription/ManageSubscription', [
            'subscription' => $subscription,
            'currentVersion' => $currentVersion, 
            'allPlanItems' => $allPlanItems,
            'mode' => $mode, // 'upgrade' o 'renew'
            'currentBillingPeriod' => $currentBillingPeriod, 
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
        ]);

        $subscription = $user->branch->subscription;
        $allPlanItems = PlanItem::where('is_active', true)->get()->keyBy('key');
        
        Log::info("Iniciando pago de suscripción por {$validated['total_amount']}");

        try {
            DB::transaction(function () use ($subscription, $validated, $allPlanItems) {
                $billingPeriod = BillingPeriod::from($validated['billing_period']);
                $mode = $validated['mode'];
                $startDate = null;
                $endDate = null;

                $currentVersion = $subscription->versions()->latest('start_date')->first();
                
                // --- LÓGICA DE FECHAS MEJORADA ---
                if ($mode === 'upgrade') {
                    // MODO MEJORA: La versión anterior se corta hoy, la nueva empieza hoy.
                    if ($currentVersion) {
                        $currentVersion->update(['end_date' => now()]);
                    }
                    $startDate = now();
                    $endDate = $billingPeriod === BillingPeriod::ANNUALLY ? now()->addYear() : now()->addMonth();

                } else { // MODO RENOVACIÓN
                    // Si hay una versión actual y AÚN NO VENCE (estamos en los 5 días)
                    if ($currentVersion && $currentVersion->end_date->isFuture()) {
                        // La nueva versión empieza cuando la actual TERMINA
                        $startDate = $currentVersion->end_date;
                        $endDate = $billingPeriod === BillingPeriod::ANNUALLY 
                            ? $startDate->copy()->addYear() 
                            : $startDate->copy()->addMonth();
                    } else {
                        // Si no hay versión o YA VENCIÓ, la nueva empieza HOY
                        $startDate = now();
                        $endDate = $billingPeriod === BillingPeriod::ANNUALLY ? now()->addYear() : now()->addMonth();
                    }
                }
                // --- FIN LÓGICA DE FECHAS ---

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
                         // El precio se calcula por la cantidad total, no por unidad de límite
                         // $unitPrice = $unitPrice / $planItem->meta['quantity']; // Esta línea parece incorrecta
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

                $newVersion->payments()->create([
                    'amount' => $validated['total_amount'],
                    'payment_method' => 'card_mock', 
                    'invoice_status' => InvoiceStatus::NOT_REQUESTED,
                ]);
                
                $subscription->update(['status' => \App\Enums\SubscriptionStatus::ACTIVE]);
            });
            
        } catch (\Exception $e) {
            Log::error("Error al procesar la suscripción: " . $e->getMessage());
            return redirect()->back()->with('error', 'Hubo un error al procesar tu pago. Por favor, intenta de nuevo.');
        }

        return redirect()->route('subscription.show')->with('success', '¡Tu suscripción ha sido actualizada con éxito!');
    }
}