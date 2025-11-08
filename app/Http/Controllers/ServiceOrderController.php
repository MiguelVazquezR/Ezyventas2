<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\ServiceOrderStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Http\Requests\UpdateServiceOrderRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Traits\OptimizeMediaLocal;

class ServiceOrderController extends Controller implements HasMiddleware
{
    use OptimizeMediaLocal;

    public static function middleware(): array
    {
        return [
            new Middleware('can:services.orders.access', only: ['index']),
            new Middleware('can:services.orders.create', only: ['create', 'store']),
            new Middleware('can:services.orders.see_details', only: ['show']),
            new Middleware('can:services.orders.edit', only: ['edit', 'update']),
            new Middleware('can:services.orders.delete', only: ['destroy', 'batchDestroy']),
            new Middleware('can:services.orders.change_status', only: ['updateStatus']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = ServiceOrder::query()
            ->where('branch_id', $branchId)
            ->with('branch:id,name');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('customer_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('item_description', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('folio', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'received_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $serviceOrders = $query->paginate($request->input('rows', 20))->withQueryString();

        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER, TemplateContextType::GENERAL])
            ->get();

        return Inertia::render('ServiceOrder/Index', [
            'serviceOrders' => $serviceOrders,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function show(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load(['branch', 'user', 'customer', 'items.itemable', 'activities.causer', 'media', 'transaction.payments.bankAccount']);

        $translations = config('log_translations.ServiceOrder', []);

        $formattedActivities = $serviceOrder->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            if (isset($activity->properties['old'])) {
                foreach ($activity->properties['old'] as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (isset($activity->properties['attributes'])) {
                foreach ($activity->properties['attributes'] as $key => $value) {
                    $changes['after'][($translations[$key] ?? $key)] = $value;
                }
            }
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                'changes' => $changes,
            ];
        });

        $availableTemplates = Auth::user()->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER, TemplateContextType::GENERAL])
            ->get();

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return Inertia::render('ServiceOrder/Show', [
            'serviceOrder' => $serviceOrder,
            'activities' => $formattedActivities,
            'availableTemplates' => $availableTemplates,
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'service_orders')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServiceOrder/Create', $this->getFormData());
    }

    /**
     * Almacena una nueva orden de servicio.
     */
    public function store(StoreServiceOrderRequest $request)
    {
        $validated = array_merge($request->validated(), $request->validate([
            'create_customer' => 'required|boolean',
            'credit_limit' => 'required_if:create_customer,true|numeric|min:0',
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
        ]));

        $newServiceOrder = null;

        DB::transaction(function () use ($validated, &$newServiceOrder, $request) {
            $user = Auth::user();
            $customer = null;

            // --- Se generan ambos folios consecutivos ---
            $folio = $this->generateServiceOrderFolio();
            $transactionFolio = $this->generateTransactionFolio();

            // --- Lógica de creación/búsqueda de cliente ---
            if (! empty($validated['customer_id'])) {
                $customer = Customer::find($validated['customer_id']);
            } elseif ($validated['create_customer']) {
                $customer = Customer::create([
                    'branch_id' => $user->branch_id,
                    'name' => $validated['customer_name'],
                    'phone' => $validated['customer_phone'] ?? null,
                    'email' => $validated['customer_email'] ?? null,
                    'address' => $validated['customer_address'] ?? null,
                    'credit_limit' => $validated['credit_limit'],
                    'balance' => 0, // El cliente nuevo inicia con balance 0
                ]);
            }

            // --- Creación de ServiceOrder ---
            $serviceOrder = ServiceOrder::create(array_merge($validated, [
                'folio' => $folio,
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'customer_id' => $customer?->id,
                'status' => ServiceOrderStatus::PENDING,
            ]));

            // --- Creación de la Transacción asociada ---
            $transaction = $serviceOrder->transaction()->create([
                'folio' => $transactionFolio,
                'customer_id' => $customer?->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'cash_register_session_id' => $validated['cash_register_session_id'],
                'subtotal' => $serviceOrder->subtotal,
                'total_discount' => $serviceOrder->discount_amount,
                'total' => $serviceOrder->final_total, 
                'total_tax' => 0,
                'channel' => TransactionChannel::SERVICE_ORDER,
                'status' => $serviceOrder->final_total > 0 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
            ]);

            // --- Lógica de cargo a Balance ---
            // Si hay un cliente y la orden tiene costo, se genera la deuda.
            if ($customer && $serviceOrder->final_total > 0) {
                
                // Decrementamos el balance (haciéndolo más negativo)
                $customer->decrement('balance', $serviceOrder->final_total);

                // Registramos el movimiento para trazabilidad
                $customer->balanceMovements()->create([
                    'transaction_id' => $transaction->id,
                    'type' => CustomerBalanceMovementType::CREDIT_SALE, 
                    'amount' => -$serviceOrder->final_total, // El monto es negativo (es un cargo)
                    'balance_after' => $customer->balance, // El balance actualizado
                    'notes' => "Cargo por Orden de Servicio #{$serviceOrder->folio}",
                    'created_at' => $transaction->created_at, 
                    'updated_at' => $transaction->created_at,
                ]);
            }
            
            // --- Lógica de Descuento de Stock ---
            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    // Limpieza de ID (código existente)
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    
                    // 1. Creamos el item de la orden (código existente)
                    $serviceOrderItem = $serviceOrder->items()->create($item);

                    // 2. ¡NUEVO! Verificamos si el item es un Producto (App\Models\Product)
                    //    y si tiene un ID (no es un servicio o item manual)
                    if ($serviceOrderItem->itemable_type === Product::class && $serviceOrderItem->itemable_id) {
                        
                        // 3. ¡NUEVO! Buscamos el producto en la base de datos
                        $product = Product::find($serviceOrderItem->itemable_id);
                        
                        // 4. ¡NUEVO! Si encontramos el producto, descontamos el stock físico
                        if ($product) {
                            // Usamos decrement para restar del 'current_stock'
                            $product->decrement('current_stock', $serviceOrderItem->quantity);
                        }
                    }
                }
            }
            // --- Lógica de Descuento de Stock ---

            // --- Lógica de Imágenes ---
            if ($request->hasFile('initial_evidence_images')) {
                foreach ($request->file('initial_evidence_images') as $file) {
                    $mediaItem = $serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

            $newServiceOrder = $serviceOrder;
        });

        // --- Redirección ---
        return redirect()->route('service-orders.show', $newServiceOrder->id)
            ->with('success', 'Orden de servicio creada.')
            ->with('show_payment_modal', true);
    }

    /**
     * Genera un folio consecutivo para las órdenes de servicio, único por suscripción.
     *
     * @return string
     */
    private function generateServiceOrderFolio(): string
    {
        $branchId = Auth::user()->branch_id;

        // Busca la última orden de servicio de la suscripción con el formato de folio específico
        $lastOrder = ServiceOrder::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-%')
            // Ordena por el valor numérico del folio para encontrar el más alto, no por ID.
            ->orderByRaw('CAST(SUBSTRING(folio, 4) AS UNSIGNED) DESC')
            ->first();

        $sequence = 1;
        if ($lastOrder) {
            // Extrae la parte numérica del folio (después de "OS-") y le suma 1
            $lastSequence = (int) substr($lastOrder->folio, 3);
            $sequence = $lastSequence + 1;
        }

        // Retorna el nuevo folio formateado con 3 dígitos (ej: OS-001)
        return 'OS-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Genera un folio consecutivo para la transacción de la orden de servicio.
     *
     * @return string
     */
    private function generateTransactionFolio(): string
    {
        $branchId = Auth::user()->branch_id;

        // Busca la última transacción de la suscripción con el formato de folio específico
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-V-%')
            // Ordena por el valor numérico del folio para encontrar el más alto
            ->orderByRaw('CAST(SUBSTRING(folio, 6) AS UNSIGNED) DESC')
            ->first();

        $sequence = 1;
        if ($lastTransaction) {
            // Extrae la parte numérica del folio (después de "OS-V-") y le suma 1
            $lastSequence = (int) substr($lastTransaction->folio, 5);
            $sequence = $lastSequence + 1;
        }

        // Retorna el nuevo folio formateado
        return 'OS-V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public function edit(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load('items.itemable');
        return Inertia::render('ServiceOrder/Edit', array_merge($this->getFormData(), ['serviceOrder' => $serviceOrder]));
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder)
    {
        DB::transaction(function () use ($request, $serviceOrder) {
            $validated = $request->validated();

            // --- INICIO DE MODIFICACIÓN: Lógica de Sincronización de Stock ---

            // 1. Obtener todos los items *antiguos* ANTES de borrarlos
            $oldItems = $serviceOrder->items()->get();

            // 2. Reponer el stock de los items antiguos (devolverlos al inventario)
            foreach ($oldItems as $oldItem) {
                // Solo reponemos si es un Producto con ID
                if ($oldItem->itemable_type === Product::class && $oldItem->itemable_id) {
                    $product = Product::find($oldItem->itemable_id);
                    if ($product) {
                        $product->increment('current_stock', $oldItem->quantity);
                    }
                }
            }

            // 3. Borrar los items antiguos de la orden (como estaba en tu código)
            $serviceOrder->items()->delete();

            // 4. Crear los items *nuevos* y descontar su stock
            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    
                    // Creamos el nuevo item
                    $newServiceOrderItem = $serviceOrder->items()->create($item);

                    // Verificamos si es un Producto y descontamos el stock
                    if ($newServiceOrderItem->itemable_type === Product::class && $newServiceOrderItem->itemable_id) {
                        $product = Product::find($newServiceOrderItem->itemable_id);
                        if ($product) {
                            // (Importante: Asegúrate de que tu validación no permita descontar más de lo que hay,
                            // o usa decrement() si estás seguro de que la disponibilidad se validó en el frontend)
                            $product->decrement('current_stock', $newServiceOrderItem->quantity);
                        }
                    }
                }
            }
            // --- FIN DE MODIFICACIÓN ---


            // --- Lógica de actualización de Orden y Transacción ---
            $serviceOrder->update($validated);

            $serviceOrder->load('transaction');
            if ($serviceOrder->transaction) {
                // --- INICIO DE MODIFICACIÓN: Actualizar el 'total' de la transacción ---
                // Cargamos el cliente para la lógica de balance
                $customer = $serviceOrder->customer; 
                
                // Obtenemos el total anterior ANTES de actualizar
                $oldTotal = $serviceOrder->transaction->total;
                $newTotal = $validated['final_total']; // Asumiendo que final_total está en $validated
                $totalDifference = $newTotal - $oldTotal;

                // Actualizamos la transacción con los nuevos totales
                $serviceOrder->transaction->update([
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['discount_amount'],
                    'total' => $newTotal, // Actualizamos el total de la deuda
                ]);

                // Si hay un cliente, ajustamos su balance con la diferencia
                if ($customer && $totalDifference != 0) {
                    // Si el nuevo total es MAYOR (totalDifference > 0), le cargamos más (decrement)
                    // Si el nuevo total es MENOR (totalDifference < 0), le devolvemos (increment)
                    $customer->decrement('balance', $totalDifference);
                    
                    // (Opcional: puedes añadir un CustomerBalanceMovement aquí)
                }
                // --- FIN DE MODIFICACIÓN ---
            }

            // --- Lógica de Imágenes ---
            if ($request->input('deleted_media_ids')) {
                $serviceOrder->media()->whereIn('id', $request->input('deleted_media_ids'))->delete();
            }

            if ($request->hasFile('initial_evidence_images')) {
                foreach ($request->file('initial_evidence_images') as $file) {
                    $mediaItem = $serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
        });

        return redirect()->route('service-orders.show', $serviceOrder->id)->with('success', 'Orden de servicio actualizada.');
    }

    public function saveDiagnosisAndEvidence(Request $request, ServiceOrder $serviceOrder)
    {
        $validated = $request->validate([
            'technician_diagnosis' => 'nullable|string|max:1000',
            'closing_evidence_images' => 'nullable|array|max:5',
            'closing_evidence_images.*' => 'image',
        ]);

        DB::transaction(function () use ($validated, $serviceOrder, $request) {
            $serviceOrder->update([
                'technician_diagnosis' => $validated['technician_diagnosis'],
            ]);

            if ($request->hasFile('closing_evidence_images')) {
                foreach ($request->file('closing_evidence_images') as $file) {
                    $mediaItem = $serviceOrder->addMedia($file)->toMediaCollection('closing-service-order-evidence');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }
        });

        return redirect()->back()->with('success', 'Diagnóstico y evidencias guardados correctamente.');
    }

    public function updateStatus(Request $request, ServiceOrder $serviceOrder)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ServiceOrderStatus::class)],
        ]);

        $oldStatus = $serviceOrder->status; // Obtenemos el Enum/valor actual
        $newStatus = ServiceOrderStatus::from($validated['status']); // Convertimos el string a Enum

        // 1. Solo ejecutar esta lógica si el nuevo estado es "cancelado"
        //    y si el estado anterior NO ERA "cancelado" (para evitar doble ejecución).
        if ($newStatus === ServiceOrderStatus::CANCELLED && $oldStatus !== ServiceOrderStatus::CANCELLED) {
            
            DB::transaction(function () use ($serviceOrder) {
                
                // 2. Cargar las relaciones necesarias
                $serviceOrder->load('items', 'transaction.customer', 'transaction.payments');

                // 3. Devolver el stock de las refacciones (Productos) al inventario
                foreach ($serviceOrder->items as $item) {
                    // Verificamos que sea un Producto (no un Servicio) y tenga ID
                    if ($item->itemable_type === Product::class && $item->itemable_id) {
                        $product = Product::find($item->itemable_id);
                        if ($product) {
                            // Incrementamos el stock físico (current_stock)
                            $product->increment('current_stock', $item->quantity);
                        }
                    }
                }

                // 5. Revertir la deuda pendiente del cliente (si aplica)
                $customer = $serviceOrder->customer;
                $transaction = $serviceOrder->transaction;

                if ($customer && $transaction) {
                    // Calculamos la deuda real pendiente de ESTA transacción
                    $totalPaid = $transaction->payments()->sum('amount');
                    $totalDebt = $transaction->total;
                    $pendingDebt = $totalDebt - $totalPaid; // Ej: 500 (deuda) - 100 (pagado) = 400 (pendiente)

                    // Si aún quedaba deuda (pendiente > 0), "perdonamos" esa deuda
                    // incrementando su balance (ej: balance -400 + 400 = 0)
                    if ($pendingDebt > 0.01) {
                        $customer->increment('balance', $pendingDebt);

                        // Registrar el movimiento para trazabilidad
                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT, // Enum de 'credito_por_cancelacion'
                            'amount' => $pendingDebt, // El monto es positivo (un abono/crédito)
                            'balance_after' => $customer->balance,
                            'notes' => "Crédito por cancelación de O.S. #{$serviceOrder->folio}",
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // 6. Actualizar el estado de la Transacción asociada a "cancelado"
                if($transaction) {
                    $transaction->update(['status' => TransactionStatus::CANCELLED]);
                }
            });
        }
        
        // 7. Actualizar el estado de la Orden de Servicio
        $serviceOrder->update(['status' => $newStatus->value]);

        // 8. Registrar la actividad (código existente)
        activity()
            ->performedOn($serviceOrder)
            ->causedBy(auth()->user())
            ->event('status_changed')
            ->withProperties(['old_status' => $oldStatus->value, 'new_status' => $newStatus->value])
            ->log("El estatus cambió de '{$oldStatus->value}' a '{$newStatus->value}'.");

        return redirect()->back()->with('success', 'Estatus de la orden actualizado y stock devuelto.');
    }

    public function destroy(ServiceOrder $serviceOrder)
    {
        DB::transaction(function () use ($serviceOrder) {
            $serviceOrder->transaction()->delete();
            $serviceOrder->delete();
        });
        return redirect()->route('service-orders.index')->with('success', 'Orden de servicio eliminada.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        $orderIds = $request->input('ids');
        DB::transaction(function () use ($orderIds) {
            \App\Models\Transaction::whereHasMorph('transactionable', [ServiceOrder::class], function ($query) use ($orderIds) {
                $query->whereIn('id', $orderIds);
            })->delete();
            ServiceOrder::whereIn('id', $orderIds)->delete();
        });
        return redirect()->route('service-orders.index')->with('success', 'Órdenes seleccionadas eliminadas.');
    }

   private function getFormData(): array
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $isOwner = !$user->roles()->exists();
        $userBankAccounts = null;

        if ($isOwner) {
            $userBankAccounts = $user->branch->bankAccounts()->get();
        } else {
            $userBankAccounts = $user->bankAccounts()->get();
        }

        return [
            'customers' => Customer::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->get(),
            'products' => Product::where('branch_id', $user->branch_id)->with('productAttributes')->get(),
            'services' => Service::where('branch_id', $user->branch_id)->get(['id', 'name', 'base_price']),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'service_orders')->get(),
            'userBankAccounts' => $userBankAccounts,
        ];
    }
}