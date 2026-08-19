<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\ChangeServiceOrderStatusAction;
use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\EnsureServiceOrderTransactionAction;
use App\Actions\ServiceOrders\UpdateServiceOrderAction;
use App\Enums\ServiceOrderStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Http\Requests\UpdateServiceOrderRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
        
        $query = ServiceOrder::query()
            ->where('branch_id', $user->branch_id)
            ->with('branch:id,name', 'transaction.payments', 'transaction.invoice');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('customer_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('item_description', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('folio', 'LIKE', "%{$searchTerm}%");
            });
        }

        $query->orderBy($request->input('sortField', 'received_at'), $request->input('sortOrder', 'desc'));

        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER])
            ->get();

        return Inertia::render('ServiceOrder/Index', [
            'serviceOrders' => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'availableTemplates' => $availableTemplates,
        ]);
    }

    public function show(Request $request, ServiceOrder $serviceOrder, ActivityLogService $activityLogService): Response
    {
        $serviceOrder->load(['branch', 'user', 'customer', 'items.itemable' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                Product::class => ['media', 'productAttributes'],
                Service::class => [],
                \App\Models\ServiceVariant::class => [],
            ]);
        }, 'media', 'transaction.payments.bankAccount', 'transaction.invoice']);

        $subscriptionId = Auth::user()->branch->subscription_id;
        
        return Inertia::render('ServiceOrder/Show', [
            'serviceOrder' => $serviceOrder,
            'activities' => $activityLogService->getFormattedActivities($serviceOrder, $request, 'ServiceOrder'),
            'availableTemplates' => Auth::user()->branch->printTemplates()
                ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
                ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER])
                ->get(),
            'printTemplates' => Auth::user()->branch->subscription->printTemplates()
                ->where('type', TemplateType::SERVICE_RECEIPT)
                ->whereIn('context_type', [TemplateContextType::SERVICE_ORDER])
                ->get(),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)
                ->where('module', 'service_orders')
                ->get(),
        ]);
    }

    /**
     * Crea la venta (transacción) asociada a la orden si aún no existe,
     * para poder registrar pagos en órdenes antiguas sin venta vinculada.
     */
    public function ensureTransaction(ServiceOrder $serviceOrder, EnsureServiceOrderTransactionAction $action): JsonResponse
    {
        $transaction = $action->execute($serviceOrder, (int) Auth::id());

        return response()->json(['transaction_id' => $transaction->id]);
    }

    public function print(Request $request, ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load([
            'branch.subscription',
            'user',
            'customer',
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => ['media'],
                    Service::class => ['media'],
                ]);
            },
            'media',
            'transaction.payments',
        ]);

        $subscriptionId = Auth::user()->branch->subscription_id;

        return Inertia::render('ServiceOrder/Print', [
            'serviceOrder' => $serviceOrder,
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)
                ->where('module', 'service_orders')
                ->get(),
            'printTemplate' => $request->has('template_id')
                ? PrintTemplate::find($request->input('template_id'))
                : null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServiceOrder/Create', $this->getFormData());
    }

    public function store(StoreServiceOrderRequest $request, CreateServiceOrderAction $action)
    {
        $validated = array_merge($request->validated(), $request->validate([
            'create_customer' => 'required|boolean',
            'credit_limit' => 'required_if:create_customer,true|numeric|min:0',
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
        ]));

        $newServiceOrder = $action->execute(
            $validated, 
            Auth::user(), 
            $request->file('initial_evidence_images')
        );

        return redirect()->route('service-orders.show', $newServiceOrder->id)
            ->with('success', 'Orden de servicio creada.')
            ->with('show_payment_modal', true);
    }

     public function edit(ServiceOrder $serviceOrder): Response
    {
        $serviceOrder->load(['items.itemable', 'media']);
        
        return Inertia::render('ServiceOrder/Edit', array_merge($this->getFormData(), ['serviceOrder' => $serviceOrder]));
    }

    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder, UpdateServiceOrderAction $action)
    {
        $action->execute(
            $serviceOrder,
            $request->validated(),
            Auth::user(),
            $request->file('initial_evidence_images'),
            $request->input('deleted_media_ids')
        );

        return redirect()->route('service-orders.show', $serviceOrder->id)
            ->with('success', 'Orden de servicio actualizada.');
    }

    public function saveDiagnosisAndEvidence(Request $request, ServiceOrder $serviceOrder)
    {
        $validated = $request->validate([
            'technician_diagnosis' => 'nullable|string|max:1000',
            'closing_evidence_images' => 'nullable|array|max:5',
            'closing_evidence_images.*' => 'image',
        ]);

        DB::transaction(function () use ($validated, $serviceOrder, $request) {
            $serviceOrder->update(['technician_diagnosis' => $validated['technician_diagnosis']]);

            if ($request->hasFile('closing_evidence_images')) {
                foreach ($request->file('closing_evidence_images') as $file) {
                    $this->optimizeMediaLocal($serviceOrder->addMedia($file)->toMediaCollection('closing-service-order-evidence'));
                }
            }
        });

        return redirect()->back()->with('success', 'Diagnóstico y evidencias guardados correctamente.');
    }

    public function updateStatus(Request $request, ServiceOrder $serviceOrder, ChangeServiceOrderStatusAction $action)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ServiceOrderStatus::class)],
        ]);

        $result = $action->execute(
            $serviceOrder, 
            ServiceOrderStatus::from($validated['status']), 
            Auth::user()
        );

        if (!$result['success']) {
            return redirect()->back();
        }

        return redirect()->back()->with('success', $result['message']);
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
        
        DB::transaction(function () use ($request) {
            Transaction::whereHasMorph('transactionable', [ServiceOrder::class], function ($query) use ($request) {
                $query->whereIn('id', $request->input('ids'));
            })->delete();
            
            ServiceOrder::whereIn('id', $request->input('ids'))->delete();
        });
        
        return redirect()->route('service-orders.index')->with('success', 'Órdenes seleccionadas eliminadas.');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS PRIVADOS
    |--------------------------------------------------------------------------
    */

    private function getFormData(): array
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return [
            'customers' => Customer::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->get(),
            'products' => Product::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))
                ->with(['productAttributes.branches', 'branches'])
                ->get()
                ->map(function ($p) use ($user) {
                    $p->loadStockForBranch($user->branch_id); // Reusando el Accessor nativo del modelo
                    return $p;
                }),
            'services' => Service::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))
                ->with('variants')
                ->get(),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)
                ->where('module', 'service_orders')
                ->get(),
            'userBankAccounts' => (!$user->roles()->exists()) 
                ? $user->branch->bankAccounts()->get() 
                : $user->bankAccounts()->get(),
        ];
    }
}