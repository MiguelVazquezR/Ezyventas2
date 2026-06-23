SaaS POS — Project guidelines for deepseekStackBackend: Laravel 12, PHP 8.3+Frontend: Vue 3 (Composition API + <script setup>), Inertia.js 2, ViteUI library: PrimeVue (always use its components — never build from scratch what PrimeVue already provides)Auth & permissions: Laravel Sanctum, Spatie Laravel PermissionStyling: Tailwind CSS (utility classes only to complement PrimeVue, never to replace it). Adhere strictly to the "Tesla UI" design system.General principlesAll code, comments, variable names, class names, methods, routes, and files must be written in EnglishAll user-facing text (labels, placeholders, messages, tooltips, validation errors) must be in Spanish and use sentence case — never title case or all caps✅ "Guardar cambios", "Este campo es obligatorio", "Órdenes de servicio"❌ "Guardar Cambios", "Este Campo Es Obligatorio", "ÓRDENES DE SERVICIO"Strip native margins from headers (use m-0 or mb-0 on h1, h2, etc.) to maintain a compact design.Follow SOLID principles strictly:Single responsibility: one class, one purposeOpen/closed: extend behavior without modifying existing codeLiskov substitution: subtypes must be replaceable for their base typesInterface segregation: prefer small, focused interfacesDependency inversion: depend on abstractions, not concretionsPrefer explicit over implicit code — clarity over clevernessNo commented-out dead code — delete itArchitectureLayer responsibilitiesHTTP Request
  → Controller       (thin — delegates immediately)
  → Action           (single-use case orchestrator)
  → Service          (reusable business logic)
  → Model            (rich domain logic, scopes, relationships, mutators)
  → FormRequest      (all validation lives here)
Controllers — keep them thinOne responsibility per method: receive request, call action or service, return responseNo business logic, no queries, no calculationsAlways use Form Requests for validationAlways use Inertia responses or JSON responses — never blade views// ✅ Correct
public function store(StoreServiceOrderRequest $request): RedirectResponse
{
    $this->createServiceOrderAction->execute($request->validated());

    return redirect()->route('service-orders.index');
}

// ❌ Wrong — business logic in controller
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([...]);
    $order = ServiceOrder::create($validated);
    $order->notify();
    return redirect()->route('service-orders.index');
}
Actions — single use casesOne action per use case: CreateServiceOrderAction, ApproveInvoiceActionLocated in app/Actions/{Module}/Must have a single public method: execute(array $data): mixedCan call multiple services, fire events, dispatch jobsnamespace App\Actions\ServiceOrders;

class CreateServiceOrderAction
{
    public function __construct(
        private readonly ServiceOrderService $serviceOrderService,
        private readonly NotificationService $notificationService,
    ) {}

    public function execute(array $data): ServiceOrder
    {
        $order = $this->serviceOrderService->create($data);
        $this->notificationService->notifyAssignedTechnician($order);

        return $order;
    }
}
Services — reusable business logicLocated in app/Services/{Module}/Handle reusable operations shared across multiple actionsMay interact with models, external APIs, or other servicesMust be injected via constructor (dependency inversion)namespace App\Services\ServiceOrders;

class ServiceOrderService
{
    public function create(array $data): ServiceOrder
    {
        return ServiceOrder::create($data);
    }

    public function calculateTotal(ServiceOrder $order): float
    {
        return $order->items->sum(fn($item) => $item->quantity * $item->unit_price);
    }
}
Models — rich and expressiveDefine all relationships, scopes, accessors, mutators, and casts hereUse $fillable explicitly — never $guarded = []Define casts for dates, booleans, enums, and JSON fieldsAdd query scopes for all common filtersclass ServiceOrder extends Model
{
    protected $fillable = [
        'customer_id', 'assigned_to', 'status', 'scheduled_at', 'notes',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'status'       => ServiceOrderStatus::class,
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    // Scopes
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ServiceOrderStatus::Pending);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }
}
Form requestsAll validation lives in Form Requests — never $request->validate() inside controllersLocated in app/Http/Requests/{Module}/Always implement authorize() using Spatie permissions or policiesUse prepareForValidation() to normalize data before validationnamespace App\Http\Requests\ServiceOrders;

class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create service-orders');
    }

    public function rules(): array
    {
        return [
            'customer_id'   => ['required', 'integer', 'exists:customers,id'],
            'scheduled_at'  => ['required', 'date', 'after:now'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Please select a customer.',
            'scheduled_at.after'   => 'The scheduled date must be in the future.',
            'items.required'       => 'Add at least one item to the order.',
        ];
    }
}
RoutesAll route files in routes/web/ use kebab-case with hyphens for URL segments✅ service-orders.php, purchase-orders.php, work-orders.php❌ serviceOrders.php, service_orders.phpUse named routes always, following the same kebab-case patternGroup routes by module and apply middleware// routes/service-orders.php
Route::middleware(['auth', 'verified'])->prefix('service-orders')->name('service-orders.')->group(function () {
    Route::get('/', [ServiceOrderController::class, 'index'])->name('index');
    Route::get('/create', [ServiceOrderController::class, 'create'])->name('create');
    Route::post('/', [ServiceOrderController::class, 'store'])->name('store');
    Route::get('/{serviceOrder}', [ServiceOrderController::class, 'show'])->name('show');
    Route::get('/{serviceOrder}/edit', [ServiceOrderController::class, 'edit'])->name('edit');
    Route::put('/{serviceOrder}', [ServiceOrderController::class, 'update'])->name('update');
    Route::delete('/{serviceOrder}', [ServiceOrderController::class, 'destroy'])->name('destroy');
});
Vue 3 frontendGeneral rulesAlways use <script setup> with Composition API — never Options APIUse defineProps and defineEmits with TypeScript-style type annotationsKeep components focused — if a component exceeds ~200 lines, split itUse PrimeVue components for all UI: forms, tables, dialogs, buttons, inputs, selects, date pickers, etc.Never build custom form inputs, modals, or tables when PrimeVue already provides them. Not necesary to import components from vue files, it is installed autoimport. Use Pass Through (:pt) extensively to enforce Tailwind styling.Component namingPages: PascalCase in resources/js/Pages/{Module}/resources/js/Pages/ServiceOrders/Index.vueresources/js/Pages/ServiceOrders/Create.vueReusable components: resources/js/Components/{Category}/resources/js/Components/Forms/CustomerSelect.vueresources/js/Components/Tables/StatusBadge.vueInertia patternsUse useForm() for all forms — never plain axios.post()Use router.visit() for programmatic navigationPass only the data the page needs from the controller — avoid over-fetchingNever add <Toast/> or <ConfirmDialog /> in individual Vue pages — they are already registered globally in AppLayout.vue and adding them per-page causes duplicate instances and double toasts.Never add onSuccess() toasts in the frontend when the controller already returns a flash message (e.g., ->with('success', ...)). The AppLayout.vue automatically picks up flash.success, flash.error, flash.warning, and flash.info from Inertia responses and displays them as toasts. Adding another toast in onSuccess duplicates the message.Only add onSuccess() toasts for actions that do NOT reload the page (preserveScroll + replace) where no controller flash is sent.<script setup>
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  customers: Array,
})

const form = useForm({
  customer_id: null,
  scheduled_at: '',
  notes: '',
  items: [],
})

function submit() {
  form.post(route('service-orders.store'), {
    onSuccess: () => { /* trigger PrimeVue toast */ },
  })
}
</script>
PrimeVue & Tesla UI usageDark Mode Base: Use absolute matte #232323 for panels/modals (dark:bg-[#232323]) and #1a1a1a for internal cards/inputs (dark:bg-[#1a1a1a]).Borders & Glows: Use ultra-thin borders (border-gray-100 dark:border-[#3a3a3a]) instead of shadows. Use pulsing dots for active/status indicators (e.g., animate-pulse).Shapes: Apply rounded-3xl for main containers, dialogs, and popovers. Use rounded-2xl for internal cards and inputs. Use rounded-full or rounded-xl for buttons/badges.Typography (Micro-copy & Telemetry): Input labels must be technical: text-[10px] uppercase tracking-widest font-bold text-gray-500. Money/Totals must be large and thin: text-3xl font-light tracking-tight.PrimeVue Overrides (:pt): Force Tailwind classes into complex PrimeVue components using :pt to remove native borders, enforce #232323 backgrounds, and rounded-3xl borders.Icons: Always use the ! modifier for sizing PrimeIcons (e.g., pi pi-search !text-sm).Validation: Use <Message severity="error" variant="simple" size="small"> for form errors.<!-- ✅ Correct: using PrimeVue and Tesla UI styling -->
<div class="flex flex-col gap-1.5 mb-4">
    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Customer *</label>
    <Select v-model="form.customer_id" :options="customers" optionLabel="name" optionValue="id" placeholder="Select a customer"
        class="w-full"
        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" 
    />
</div>

<div class="flex flex-col gap-1.5 mb-4">
    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Total amount</label>
    <InputNumber v-model="form.amount" mode="currency" currency="MXN" 
        class="w-full"
        :pt="{ 
            input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-2xl !font-light !text-gray-900 dark:!text-white' } } 
        }" 
    />
</div>

<Button type="button" :loading="form.processing" @click="submit" class="!rounded-full">
  Save service order
</Button>
Permissions (Spatie)Define all permissions using kebab-case: create service-orders, edit invoices, delete customersAlways check permissions in Form Requests (authorize()) and in Vue with a can prop passed from the controllerNever hardcode role checks in controllers — use permissions, not roles// ✅ Check permission
$this->user()->can('create service-orders');

// ❌ Check role directly
$this->user()->hasRole('admin');
File & folder structureapp/
├── Actions/
│   └── ServiceOrders/
│       ├── CreateServiceOrderAction.php
│       └── UpdateServiceOrderAction.php
├── Http/
│   ├── Controllers/
│   │   └── ServiceOrders/
│   │       └── ServiceOrderController.php
│   └── Requests/
│       └── ServiceOrders/
│           ├── StoreServiceOrderRequest.php
│           └── UpdateServiceOrderRequest.php
├── Models/
│   └── ServiceOrder.php
├── Services/
│   └── ServiceOrders/
│       └── ServiceOrderService.php

resources/js/
├── Pages/
│   └── ServiceOrders/
│       ├── Index.vue
│       ├── Create.vue
│       ├── Edit.vue
│       └── Show.vue
├── Components/
│   ├── Forms/
│   └── Tables/

routes/web/
├── service-orders.php
├── customers.php
└── invoices.php
What to avoid❌ Business logic inside controllers❌ $request->validate() inside controllers — always use Form Requests❌ $guarded = [] in models — always define $fillable❌ Raw queries when Eloquent scopes can do the job❌ Options API in Vue — always use <script setup>❌ Custom modal or form components when PrimeVue provides them❌ Title Case in user-facing text — always sentence case❌ Checking roles directly — always check permissions❌ Spanish variable names, method names, or comments — all code in English