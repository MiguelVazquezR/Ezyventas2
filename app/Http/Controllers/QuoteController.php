<?php

namespace App\Http\Controllers;

use App\Actions\Quote\ChangeQuoteStatusAction;
use App\Actions\Quote\ConvertQuoteToSaleAction;
use App\Actions\Quote\StoreQuoteAction;
use App\Actions\Quote\UpdateQuoteAction;
use App\Enums\QuoteStatus;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quote;
use App\Models\Service;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:quotes.access', only: ['index', 'print']),
            new Middleware('can:quotes.create', only: ['create', 'store', 'newVersion']),
            new Middleware('can:quotes.see_details', only: ['show']),
            new Middleware('can:quotes.edit', only: ['edit', 'update']),
            new Middleware('can:quotes.delete', only: ['destroy', 'batchDestroy']),
            new Middleware('can:quotes.change_status', only: ['updateStatus']),
            new Middleware('can:quotes.create_sale', only: ['convertToSale']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $query = Quote::query()
            ->whereNull('parent_quote_id')
            ->leftJoin('customers', 'quotes.customer_id', '=', 'customers.id')
            ->whereHas('branch.subscription', fn ($q) => $q->where('id', $subscriptionId))
            ->with(['customer:id,name', 'versions.customer:id,name'])
            ->select('quotes.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('folio', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('customers.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $query->orderBy($sortField === 'customer.name' ? 'customers.name' : $sortField, $request->input('sortOrder', 'desc'));

        return Inertia::render('Quote/Index', [
            'quotes' => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Quote/Create', $this->getFormData());
    }

    public function store(StoreQuoteRequest $request, StoreQuoteAction $action)
    {
        $validatedData = array_merge($request->validated(), $this->validateCustomFields($request));

        $action->execute($validatedData, Auth::user());

        return redirect()->route('quotes.index')->with('success', 'Cotización creada con éxito.');
    }

    public function edit(Quote $quote): Response
    {
        $quote->load('items.itemable');

        return Inertia::render('Quote/Edit', array_merge($this->getFormData(), ['quote' => $quote]));
    }

    public function update(UpdateQuoteRequest $request, Quote $quote, UpdateQuoteAction $action)
    {
        $validatedData = array_merge($request->validated(), $this->validateCustomFields($request));

        $action->execute($quote, $validatedData, Auth::user());

        return redirect()->route('quotes.index')->with('success', 'Cotización actualizada con éxito.');
    }

    public function show(Request $request, Quote $quote, ActivityLogService $activityLogService): Response
    {
        $quote->load([
            'customer', 
            'user', 
            'parent.versions', 
            'versions', 
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => ['media'],
                    Service::class => ['media'],
                    ProductAttribute::class => ['product.media'], 
                ]);
            }
        ]);

        $subscriptionId = Auth::user()->branch->subscription_id;

        return Inertia::render('Quote/Show', [
            'quote' => $quote,
            'activities' => $activityLogService->getFormattedActivities($quote, $request, 'Quote', true),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'quotes')->get(),
            'printTemplates' => PrintTemplate::where('subscription_id', $subscriptionId)
                ->where('type', 'cotizacion')
                ->whereHas('branches', fn ($q) => $q->where('branches.id', $quote->branch_id))
                ->select('id', 'name')
                ->get(),
        ]);
    }

    public function updateStatus(Request $request, Quote $quote, ChangeQuoteStatusAction $action, ConvertQuoteToSaleAction $convertAction)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(QuoteStatus::class)],
        ]);

        $newStatus = QuoteStatus::from($validated['status']);

        // Shortcut: Si el estado es convertir a venta, usamos la acción dedicada.
        if ($newStatus === QuoteStatus::SALE_GENERATED && !$quote->transaction_id) {
            try {
                $convertAction->execute($quote, Auth::user());
                return redirect()->back()->with('success', 'Venta generada automáticamente desde el cambio de estatus.');
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage());
            }
        }

        // De lo contrario, procedemos con el cambio de estado (que maneja las cancelaciones)
        $result = $action->execute($quote, $newStatus, Auth::user());

        if (!$result['success']) {
            return redirect()->back();
        }

        return redirect()->back()->with('success', $result['message']);
    }

    public function newVersion(Quote $quote)
    {
        $newQuote = $quote->createNewVersion();

        return redirect()->route('quotes.edit', $newQuote->id);
    }

    public function convertToSale(Request $request, Quote $quote, ConvertQuoteToSaleAction $action)
    {
        try {
            $newTransaction = $action->execute($quote, Auth::user());

            return redirect()->route('quotes.show', $quote->id)
                ->with('success', 'Cotización convertida a venta con éxito. Folio de Venta: ' . $newTransaction->folio)
                ->with('transaction_id', $newTransaction->id);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Quote $quote)
    {
        $quote->delete();
        return redirect()->route('quotes.index')->with('success', 'Cotización eliminada con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Quote::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('quotes.index')->with('success', 'Cotizaciones seleccionadas eliminadas.');
    }

    public function print(Request $request, Quote $quote): Response
    {
        $quote->load([
            'customer', 
            'branch.subscription',
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => ['media'],
                    Service::class => ['media'],
                    ProductAttribute::class => ['product.media'], 
                ]);
            }
        ]);

        $subscriptionId = Auth::user()->branch->subscription_id;

        return Inertia::render('Quote/Print', [
            'quote' => $quote,
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'quotes')->get(),
            'printTemplate' => $request->has('template_id') ? PrintTemplate::find($request->input('template_id')) : null,
        ]);
    }

    // --- HELPERS ---

    private function getFormData()
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return [
            'customers' => Customer::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->get(['id', 'name']),
            'products' => Product::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))
                ->with(['productAttributes.branches', 'branches'])
                ->get()
                ->map(function ($p) use ($user) {
                    // Reutilizamos el helper que creamos antes en Product
                    return $p->loadStockForBranch($user->branch_id);
                }),
            'services' => Service::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))->with('variants')->get(),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'quotes')->get(),
        ];
    }

    private function validateCustomFields(Request $request)
    {
        $subscriptionId = $request->user()->branch->subscription_id;
        $definitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        if ($definitions->isEmpty()) return ['custom_fields' => []];

        $rules = [];
        $messages = [];

        foreach ($definitions as $field) {
            $ruleKey = 'custom_fields.' . $field->key;
            $rules[$ruleKey] = ['nullable'];
            $messages["{$ruleKey}.*"] = "El campo {$field->name} es inválido.";

            switch ($field->type) {
                case 'text':
                case 'textarea':
                    $rules[$ruleKey] = array_merge($rules[$ruleKey], ['string', 'max:255']);
                    break;
                case 'number':
                    $rules[$ruleKey][] = 'numeric';
                    break;
                case 'boolean':
                    $rules[$ruleKey][] = 'boolean';
                    break;
                case 'select':
                    $rules[$ruleKey][] = 'string';
                    if (!empty($field->options)) $rules[$ruleKey][] = Rule::in($field->options);
                    break;
                case 'checkbox':
                    $rules[$ruleKey] = 'array';
                    $rules["{$ruleKey}.*"] = ['string', Rule::in($field->options ?? [])];
                    break;
                case 'pattern':
                    $rules[$ruleKey] = 'array';
                    $rules["{$ruleKey}.*"] = 'integer';
                    break;
            }
        }

        return $request->validate(array_merge(['custom_fields' => ['nullable', 'array']], $rules), $messages);
    }
}