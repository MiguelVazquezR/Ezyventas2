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
use App\Models\ProductAttribute;
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

        // --- CORRECCIÓN: Lógica robusta para formatear actividades y evitar problemas de indexación ---
        $formattedActivities = $serviceOrder->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            
            // Obtener propiedades de forma segura
            $oldProps = $activity->properties->get('old', []);
            $newProps = $activity->properties->get('attributes', []);

            if (is_array($oldProps)) {
                foreach ($oldProps as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (is_array($newProps)) {
                foreach ($newProps as $key => $value) {
                    // Solo incluir si es nuevo o si cambió respecto al valor anterior
                    if (!array_key_exists($key, $oldProps) || $oldProps[$key] !== $value) {
                        $changes['after'][($translations[$key] ?? $key)] = $value;
                    }
                }
            }
            
            // Limpiar 'before' para dejar solo lo que realmente cambió
            $changes['before'] = array_intersect_key($changes['before'], $changes['after']);

            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                // Asegurar que changes sea un objeto si está vacío
                'changes' => (object)(!empty($changes['before']) || !empty($changes['after']) ? $changes : []),
            ];
        })
        ->filter(fn($activity) => $activity['event'] !== 'updated' || !empty((array)$activity['changes'])) // Filtrar updates vacíos
        ->values(); // Reindexar array para evitar { "0": ... } en JSON

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

            $folio = $this->generateServiceOrderFolio();
            $transactionFolio = $this->generateTransactionFolio();

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
                    'balance' => 0,
                ]);
            }

            $serviceOrder = ServiceOrder::create(array_merge($validated, [
                'folio' => $folio,
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'customer_id' => $customer?->id,
                'status' => ServiceOrderStatus::PENDING,
            ]));

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

            if ($customer && $serviceOrder->final_total > 0) {
                $customer->decrement('balance', $serviceOrder->final_total);

                $customer->balanceMovements()->create([
                    'transaction_id' => $transaction->id,
                    'type' => CustomerBalanceMovementType::CREDIT_SALE, 
                    'amount' => -$serviceOrder->final_total,
                    'balance_after' => $customer->balance,
                    'notes' => "Cargo por Orden de Servicio #{$serviceOrder->folio}",
                    'created_at' => $transaction->created_at, 
                    'updated_at' => $transaction->created_at,
                ]);
            }
            
            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    
                    $serviceOrderItem = $serviceOrder->items()->create($item);

                    if ($serviceOrderItem->itemable_type === Product::class && $serviceOrderItem->itemable_id) {
                        $product = Product::find($serviceOrderItem->itemable_id);
                        if ($product) $product->decrement('current_stock', $serviceOrderItem->quantity);
                    }
                    elseif ($serviceOrderItem->itemable_type === ProductAttribute::class && $serviceOrderItem->itemable_id) {
                        $variant = ProductAttribute::find($serviceOrderItem->itemable_id);
                        if ($variant) $variant->decrement('current_stock', $serviceOrderItem->quantity);
                    }
                }
            }

            if ($request->hasFile('initial_evidence_images')) {
                foreach ($request->file('initial_evidence_images') as $file) {
                    $mediaItem = $serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence');
                    $this->optimizeMediaLocal($mediaItem);
                }
            }

            $newServiceOrder = $serviceOrder;
        });

        return redirect()->route('service-orders.show', $newServiceOrder->id)
            ->with('success', 'Orden de servicio creada.')
            ->with('show_payment_modal', true);
    }

    private function generateServiceOrderFolio(): string
    {
        $branchId = Auth::user()->branch_id;
        $lastOrder = ServiceOrder::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 4) AS UNSIGNED) DESC')
            ->first();

        $sequence = 1;
        if ($lastOrder) {
            $lastSequence = (int) substr($lastOrder->folio, 3);
            $sequence = $lastSequence + 1;
        }

        return 'OS-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    private function generateTransactionFolio(): string
    {
        $branchId = Auth::user()->branch_id;
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-V-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 6) AS UNSIGNED) DESC')
            ->first();

        $sequence = 1;
        if ($lastTransaction) {
            $lastSequence = (int) substr($lastTransaction->folio, 5);
            $sequence = $lastSequence + 1;
        }

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
            $itemsChanged = false; // Flag para detectar cambios en items

            // --- 1. Reponer stock antiguo ---
            $oldItems = $serviceOrder->items()->get();
            foreach ($oldItems as $oldItem) {
                if ($oldItem->itemable_type === Product::class && $oldItem->itemable_id) {
                    $product = Product::find($oldItem->itemable_id);
                    if ($product) $product->increment('current_stock', $oldItem->quantity);
                } elseif ($oldItem->itemable_type === ProductAttribute::class && $oldItem->itemable_id) {
                    $variant = ProductAttribute::find($oldItem->itemable_id);
                    if ($variant) $variant->increment('current_stock', $oldItem->quantity);
                }
            }

            // --- 2. Comparar si los items cambiaron antes de borrar ---
            // Simple verificación de conteo o contenido podría hacerse aquí, 
            // pero como borramos y creamos, asumimos que si hay items en request, hubo acción sobre items.
            if ($oldItems->count() > 0 || !empty($validated['items'])) {
                 $itemsChanged = true;
            }

            $serviceOrder->items()->delete();

            // --- 3. Crear nuevos y descontar stock ---
            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    
                    $newServiceOrderItem = $serviceOrder->items()->create($item);

                    if ($newServiceOrderItem->itemable_type === Product::class && $newServiceOrderItem->itemable_id) {
                        $product = Product::find($newServiceOrderItem->itemable_id);
                        if ($product) $product->decrement('current_stock', $newServiceOrderItem->quantity);
                    } elseif ($newServiceOrderItem->itemable_type === ProductAttribute::class && $newServiceOrderItem->itemable_id) {
                        $variant = ProductAttribute::find($newServiceOrderItem->itemable_id);
                        if ($variant) $variant->decrement('current_stock', $newServiceOrderItem->quantity);
                    }
                }
            }

            // --- Actualización de Orden ---
            $serviceOrder->update($validated);
            
            // --- AÑADIDO: Registro manual si se tocaron items ---
            // Esto es necesario porque al borrar y recrear, Spatie no detecta "cambios" en atributos del modelo padre automáticamente
            if ($itemsChanged) {
                 activity()
                    ->performedOn($serviceOrder)
                    ->causedBy(Auth::user())
                    ->event('updated')
                    ->log('Se actualizaron los conceptos (refacciones/servicios) de la orden.');
            }

            // --- Actualización de Transacción ---
            $serviceOrder->load('transaction');
            if ($serviceOrder->transaction) {
                $customer = $serviceOrder->customer; 
                $oldTotal = $serviceOrder->transaction->total;
                $newTotal = $validated['final_total'];
                $totalDifference = $newTotal - $oldTotal;

                $serviceOrder->transaction->update([
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['discount_amount'],
                    'total' => $newTotal,
                ]);

                if ($customer && $totalDifference != 0) {
                    $customer->decrement('balance', $totalDifference);
                }
            }

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

        $oldStatus = $serviceOrder->status;
        $newStatus = ServiceOrderStatus::from($validated['status']);

        if ($newStatus === ServiceOrderStatus::CANCELLED && $oldStatus !== ServiceOrderStatus::CANCELLED) {
            
            DB::transaction(function () use ($serviceOrder) {
                $serviceOrder->load('items', 'transaction.customer', 'transaction.payments');

                foreach ($serviceOrder->items as $item) {
                    if ($item->itemable_type === Product::class && $item->itemable_id) {
                        $product = Product::find($item->itemable_id);
                        if ($product) $product->increment('current_stock', $item->quantity);
                    } elseif ($item->itemable_type === ProductAttribute::class && $item->itemable_id) {
                        $variant = ProductAttribute::find($item->itemable_id);
                        if ($variant) $variant->increment('current_stock', $item->quantity);
                    }
                }

                $customer = $serviceOrder->customer;
                $transaction = $serviceOrder->transaction;

                if ($customer && $transaction) {
                    $totalPaid = $transaction->payments()->sum('amount');
                    $totalDebt = $transaction->total;
                    $pendingDebt = $totalDebt - $totalPaid;

                    if ($pendingDebt > 0.01) {
                        $customer->increment('balance', $pendingDebt);
                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                            'amount' => $pendingDebt,
                            'balance_after' => $customer->balance,
                            'notes' => "Crédito por cancelación de O.S. #{$serviceOrder->folio}",
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if($transaction) {
                    $transaction->update(['status' => TransactionStatus::CANCELLED]);
                }
            });
        }
        
        $serviceOrder->update(['status' => $newStatus->value]);

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