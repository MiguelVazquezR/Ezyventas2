<?php

namespace App\Http\Controllers;

// Imports de Enums y Modelos
use App\Enums\BillingPeriod;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPaymentStatus;
use App\Mail\AdminNewPaymentNotification;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
// Imports de Laravel
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
                $query->with(['items', 'payments'])->latest('id');
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
        $currentVersion = $subscription->versions->first(); // Ya viene ordenada por latest('id')
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

        // --- INICIO: Lógica de Comparación de Items del Historial ---
        // Obtenemos la colección de versiones ya cargada
        $versions = $subscription->versions;

        // Iteramos sobre la colección y añadimos los 'processed_items'
        $versions->map(function ($version, $index) use ($versions) {
            // La versión "anterior" (cronológicamente) es la siguiente en el array
            // porque están ordenadas por latest('id') (descendente).
            $previousVersion = $versions->get($index + 1);

            // Creamos un mapa de los items anteriores para búsqueda rápida
            $previousItemsMap = $previousVersion ? $previousVersion->items->keyBy('item_key') : collect();

            // Procesamos los items de la versión actual
            $processedItems = $version->items->map(function ($newItem) use ($previousItemsMap) {
                $previousItem = $previousItemsMap->get($newItem->item_key);
                $previousQuantity = $previousItem ? $previousItem->quantity : 0;
                $newQuantity = $newItem->quantity;
                $status = 'unchanged'; // Default

                if (!$previousItem) {
                    // El item no existía en la versión anterior
                    $status = 'new';
                } elseif ($newQuantity > $previousQuantity) {
                    // La cantidad aumentó
                    $status = 'upgraded';
                } elseif ($newQuantity < $previousQuantity) {
                    // La cantidad disminuyó
                    $status = 'downgraded';
                }

                // Devolvemos un array simple con la info necesaria para la vista
                return [
                    'name' => $newItem->name,
                    'quantity' => $newQuantity,
                    'billing_period' => $newItem->billing_period,
                    'unit_price' => $newItem->unit_price,
                    'status' => $status,
                    'previous_quantity' => $previousQuantity,
                    'item_key' => $newItem->item_key,
                    'item_type' => $newItem->item_type,
                ];
            });

            // Añadimos la nueva propiedad 'processed_items' a cada objeto de versión
            $version->processed_items = $processedItems;
            return $version;
        });
        // --- FIN: Lógica de Comparación de Items del Historial ---

        return Inertia::render('Subscription/Show', [
            'subscription' => $subscription, // $subscription ahora contiene las versiones con 'processed_items'
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
            // --- NUEVOS CAMPOS ---
            'contact_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            // Validación de horario (para la sucursal principal)
            'operating_hours' => 'nullable|array|size:7',
            'operating_hours.*.day' => 'required|string',
            'operating_hours.*.open' => 'required|boolean',
            'operating_hours.*.from' => 'nullable|date_format:H:i',
            'operating_hours.*.to' => 'nullable|date_format:H:i',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // 1. Actualizar Suscripción
            $user->branch->subscription->update([
                'commercial_name' => $validated['commercial_name'],
                'business_name' => $validated['business_name'],
                'contact_phone' => $validated['contact_phone'],
                // Guardamos la dirección como array para respetar el cast del Modelo
                'address' => $validated['address']
                    ? ['text' => $validated['address']]
                    : null,
            ]);

            // 2. Actualizar Horario en la Sucursal del Usuario (Principal)
            if (isset($validated['operating_hours'])) {
                $user->branch->update([
                    'operating_hours' => $validated['operating_hours']
                ]);
            }
        });

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

        // --- INICIO: Lógica de Detección de Reintento ---
        $versionToDisplay = $subscription->versions()
            ->with('items')
            ->latest('id')
            ->first();

        $previousVersion = null;
        $isRetry = false;
        $versionForLogic = $versionToDisplay; // Por defecto, la lógica se basa en la versión actual

        if ($versionToDisplay) {
            $lastPayment = $versionToDisplay->payments()->latest('id')->first();
            $isRetry = $lastPayment?->status === SubscriptionPaymentStatus::REJECTED;

            if ($isRetry) {
                // Es un reintento. Necesitamos la versión ANTERIOR para la lógica.
                $previousVersion = $subscription->versions()
                    ->with('items')
                    ->where('id', '!=', $versionToDisplay->id)
                    ->latest('id')
                    ->first();

                $versionForLogic = $previousVersion; // La lógica (cálculo de modo, etc.) se basará en el plan anterior.
            }
        }
        // --- FIN: Lógica de Detección de Reintento ---

        $mode = 'renew';
        $currentBillingPeriod = BillingPeriod::ANNUALLY;

        // --- INICIO: Lógica de Modo MODIFICADA ---
        if ($isRetry) {
            // PUNTO 1: Si es reintento, FORZAMOS el modo 'upgrade'.
            // Esto asegura que la vista calcule la diferencia de costos.
            $mode = 'upgrade';

            // Usamos el periodo de la versión anterior para los cálculos prorrateados
            if ($versionForLogic) {
                $firstItem = $versionForLogic->items->first();
                if ($firstItem && $firstItem->billing_period) {
                    $currentBillingPeriod = $firstItem->billing_period;
                }
            }
        } else if ($versionForLogic) {
            // Lógica normal si NO es reintento
            $endDate = Carbon::parse($versionForLogic->end_date);

            // Definimos "modo mejora" solo si falta MÁS de 5 días.
            if ($endDate->isFuture() && now()->diffInDays($endDate) > 5) {
                $mode = 'upgrade';
            }

            $firstItem = $versionForLogic->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }
        // --- FIN: Lógica de Modo MODIFICADA ---


        // Obtenemos las cuentas favoritas de la SUCURSAL 1 (la nuestra) para mostrarlas al cliente
        $ourBankAccounts = BankAccount::whereHas('branches', function ($query) {
            $query->where('branch_id', 1)->where('is_favorite', true);
        })->get();

        // --- INICIO: Lógica para Gasto Opcional (NUEVO) ---
        $isOwner = !$user->roles()->exists();
        $userBankAccounts = [];

        // Lógica basada en ExpenseController@create
        if ($isOwner) {
            // El propietario ve las cuentas de SU sucursal (la principal)
            // Asumimos que el propietario quiere ver todas las cuentas de la suscripción
            $userBankAccounts = $subscription->bankAccounts()->get(['bank_accounts.id', 'account_name', 'bank_name']);
        } else {
            // El empleado ve solo las cuentas asignadas a ÉL
            $userBankAccounts = $user->bankAccounts()->get(['bank_accounts.id', 'account_name', 'bank_name']);
        }

        // Cargar categorías de gasto de la suscripción
        $expenseCategories = ExpenseCategory::where('subscription_id', $subscription->id)
            ->get(['id', 'name']);
        // --- FIN: Lógica para Gasto Opcional ---

        return Inertia::render('Subscription/ManageSubscription', [
            'subscription' => $subscription,
            'currentVersion' => $versionToDisplay, // La versión para "mostrar" (puede ser la rechazada)
            'previousVersion' => $previousVersion, // La versión "anterior" (para comparar costos)
            'isRetry' => $isRetry, // Flag para la vista
            'allPlanItems' => $allPlanItems,
            'mode' => $mode, // 'upgrade' o 'renew'
            'currentBillingPeriod' => $currentBillingPeriod,
            'ourBankAccounts' => $ourBankAccounts,
            'hasPendingPayment' => $hasPendingPayment,
            'userBankAccounts' => $userBankAccounts,
            'expenseCategories' => $expenseCategories,
        ]);
    }

    /**
     * Revierte una versión rechazada.
     */
    public function revert(Request $request)
    {
        $subscription = $request->user()->branch->subscription;

        // Obtener la última versión (la que debería estar rechazada)
        $latestVersion = $subscription->versions()->latest('id')->first();

        if ($latestVersion) {
            $lastPayment = $latestVersion->payments()->latest('id')->first();

            // Asegurarnos que solo borramos si el último pago fue RECHAZADO
            if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {

                try {
                    DB::transaction(function () use ($latestVersion, $lastPayment, $subscription) {

                        // --- INICIO: Lógica para borrar Gasto Opcional (NUEVO) ---
                        // 1. Buscar el gasto 'pendiente' que coincida con este pago
                        $pendingExpense = Expense::where('status', ExpenseStatus::PENDING)
                            ->where('amount', $lastPayment->amount) // Coincidir monto
                            ->where('description', 'like', 'Pago de suscripción%') // Coincidir descripción
                            ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id)) // Coincidir suscripción
                            ->latest('created_at')
                            ->first();

                        // 2. Si se encuentra, eliminarlo
                        if ($pendingExpense) {
                            $pendingExpense->delete();
                        }
                        // --- FIN: Lógica de Gasto Opcional ---

                        // 3. Borrar la versión. Los items y pagos se borrarán por 'cascade'
                        $latestVersion->delete();
                    });
                    return redirect()->route('subscription.show')->with('success', 'Tu plan ha sido revertido a la versión anterior.');
                } catch (\Exception $e) {
                    Log::error("Error al revertir la versión {$latestVersion->id}: " . $e->getMessage());
                    return redirect()->back()->with('error', 'No se pudo revertir el plan. Intenta de nuevo.');
                }
            }
        }

        return redirect()->back()->with('error', 'No se encontró una versión fallida para revertir.');
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
            'payment_method' => ['required', Rule::in(['transferencia', 'stripe', 'card_mock', 'tarjeta'])],
            'proof_of_payment' => ['nullable', 'required_if:payment_method,transferencia', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'bank_account_id' => 'nullable|numeric|exists:bank_accounts,id',
            'expense_category_id' => [Rule::requiredIf($request->bank_account_id != null)],
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
            if ($validated['payment_method'] === 'transferencia') {
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
        if ($validated['payment_method'] === 'transferencia') {
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

            // --- Lógica de Versión Anterior/Actual MODIFICADA ---
            $latestVersion = $subscription->versions()->latest('id')->first();
            $baseVersion = $latestVersion; // La versión sobre la que se calcula el tiempo

            if ($latestVersion) {
                $lastPayment = $latestVersion->payments()->latest('id')->first();
                if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
                    // Es un reintento. La lógica de fechas se basa en la versión ANTERIOR.
                    $baseVersion = $subscription->versions()
                        ->where('id', '!=', $latestVersion->id)
                        ->latest('id')
                        ->first();
                }
            }
            // --- Fin Lógica de Versión ---


            // --- LÓGICA DE FECHAS (ESTIMACIÓN) ---
            if ($mode === 'upgrade') {
                // REGLA 1: MODO MEJORA
                $startDate = now();
                $endDate = $baseVersion ? $baseVersion->end_date : now()->addMonth(); // Fallback
            } else {
                // MODO RENOVACIÓN (REGLA 4)
                if ($baseVersion && $baseVersion->end_date->isFuture()) {
                    // Si paga ANTES, la nueva versión empieza cuando la actual TERMINA
                    $startDate = $baseVersion->end_date;
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
            $pendingVersion = $subscription->versions()
                ->whereHas('payments', function ($query) {
                    $query->where('status', SubscriptionPaymentStatus::REJECTED);
                })
                ->whereDoesntHave('payments', function ($query) {
                    // Asegurarse que no tenga ya un pago aprobado o pendiente
                    $query->whereIn('status', [SubscriptionPaymentStatus::APPROVED, SubscriptionPaymentStatus::PENDING]);
                })
                ->latest('id')
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

            // --- INICIO: Lógica de Gasto Opcional (NUEVO) ---
            $bankAccountId = $validated['bank_account_id'] ?? null;
            $expenseCategoryId = $validated['expense_category_id'] ?? null;

            // Solo crear el gasto si se proporcionaron ambos campos
            // La validación de que pertenecen al usuario debe estar en processManagement
            Log::info($bankAccountId);
            Log::info($expenseCategoryId);
            if ($bankAccountId && $expenseCategoryId) {
                $user = $request->user();
                $folio = $mode === 'upgrade'
                    ? 'Pago de mejora de suscripción EzyVentas'
                    : 'Pago de renovación de suscripción EzyVentas';

                Expense::create([
                    'folio' => $folio,
                    'user_id' => $user->id,
                    'branch_id' => $user->branch_id,
                    'amount' => $validated['total_amount'],
                    'expense_category_id' => $expenseCategoryId,
                    'expense_date' => now(),
                    'status' => ExpenseStatus::PENDING, // PENDIENTE hasta que el admin apruebe
                    'description' => 'Pago de suscripción ' . config('app.name'),
                    'payment_method' => PaymentMethod::TRANSFER,
                    'bank_account_id' => $bankAccountId,
                ]);
            }
            // --- FIN: Lógica de Gasto Opcional ---

            // --- INICIO: Notificar al Admin ---
            try {
                // 1. Buscar al usuario admin (asumiendo que está en la suscripción 1)
                $adminUser = User::whereHas('branch', fn($q) => $q->where('subscription_id', 1))
                    ->select('email') // Solo necesitamos el email
                    ->first();

                if ($adminUser) {
                    // 2. Preparar los datos para el Mailable
                    $subscriptionName = $subscription->commercial_name;
                    $paymentAmount = (float) $payment->amount;
                    // Generamos la URL para el panel de admin
                    $reviewUrl = route('admin.payments.show', $payment->id);

                    // 3. Enviar el correo (se encolará si Mailable implementa ShouldQueue)
                    if (app()->environment('production')) {
                        Mail::to($adminUser->email)
                            ->send(new AdminNewPaymentNotification($subscriptionName, $paymentAmount, $reviewUrl));
                    }
                } else {
                    Log::warning("No se encontró un usuario admin (Suscripción ID 1) para notificar el pago pendiente ID: {$payment->id}");
                }
            } catch (\Exception $e) {
                // Capturar error de correo para no romper la transacción del pago
                Log::error("Fallo al enviar correo de notificación de pago: " . $e->getMessage());
            }
            // --- FIN: Notificar al Admin ---

            // NO actualizamos el estado de la suscripción (status) hasta la aprobación del admin
        });
    }
}
