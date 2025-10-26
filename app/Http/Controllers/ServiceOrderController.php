<?php

namespace App\Http\Controllers;

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
                'total_tax' => 0,
                'channel' => TransactionChannel::SERVICE_ORDER,
                'status' => $serviceOrder->final_total > 0 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
            ]);

            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    $serviceOrder->items()->create($item);
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

            $serviceOrder->update($validated);

            $serviceOrder->items()->delete();

            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    if (isset($item['itemable_id']) && $item['itemable_id'] == 0) {
                        unset($item['itemable_id']);
                    }
                    $serviceOrder->items()->create($item);
                }
            }

            $serviceOrder->load('transaction');
            if ($serviceOrder->transaction) {
                $serviceOrder->transaction->update([
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['discount_amount'],
                ]);
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

        $oldStatus = $serviceOrder->status->value;
        $newStatus = $validated['status'];

        $serviceOrder->update(['status' => $newStatus]);

        activity()
            ->performedOn($serviceOrder)
            ->causedBy(auth()->user())
            ->event('status_changed')
            ->withProperties(['old_status' => $oldStatus, 'new_status' => $newStatus])
            ->log("El estatus cambió de '{$oldStatus}' a '{$newStatus}'.");

        return redirect()->back()->with('success', 'Estatus de la orden actualizado.');
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