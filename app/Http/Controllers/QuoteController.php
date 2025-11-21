<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\QuoteStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            })
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
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField === 'customer.name' ? 'customers.name' : $sortField, $sortOrder);

        $quotes = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Quote/Index', [
            'quotes' => $quotes,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Quote/Create', $this->getFormData());
    }

    public function store(StoreQuoteRequest $request)
    {
        DB::transaction(function () use ($request) {
            $user = Auth::user();
            $validated = $request->validated();

            $customFieldsData = $this->validateCustomFields($request);
            $validatedData = array_merge($validated, $customFieldsData);

            $lastQuote = Quote::where('branch_id', $user->branch_id)->latest('id')->first();
            $nextFolioNumber = $lastQuote ? (int) substr($lastQuote->folio, 4) + 1 : 1;
            $folio = 'COT-' . $nextFolioNumber;

            $quote = Quote::create(array_merge($validatedData, [
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'folio' => $folio,
                'status' => QuoteStatus::DRAFT,
            ]));

            foreach ($validatedData['items'] as $item) {
                $quote->items()->create($item);
            }
        });

        return redirect()->route('quotes.index')->with('success', 'Cotización creada con éxito.');
    }

    public function edit(Quote $quote): Response
    {
        $quote->load('items.itemable');

        return Inertia::render('Quote/Edit', array_merge($this->getFormData(), ['quote' => $quote]));
    }

    public function update(UpdateQuoteRequest $request, Quote $quote)
    {
        DB::transaction(function () use ($request, $quote) {
            $validated = $request->validated();
            $customFieldsData = $this->validateCustomFields($request);
            $validatedData = array_merge($validated, $customFieldsData);

            $quote->update($validatedData);

            $quote->items()->delete(); 
            foreach ($validatedData['items'] as $item) {
                $quote->items()->create($item);
            }
        });

        return redirect()->route('quotes.index')->with('success', 'Cotización actualizada con éxito.');
    }

    public function show(Quote $quote): Response
    {
        // CORRECCIÓN: Cargar explícitamente 'product' además de 'product.media'
        $quote->load([
            'customer', 
            'user', 
            'parent.versions', 
            'versions', 
            'activities.causer',
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => ['media'],
                    Service::class => ['media'],
                    // IMPORTANTE: Cargar 'product' y 'product.media' asegura que la relación padre esté disponible en el JSON
                    ProductAttribute::class => ['product.media'], 
                ]);
            }
        ]);

        $translations = config('log_translations.Quote', []);
        $formattedActivities = $quote->activities->map(function ($activity) use ($translations) {
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

        $subscriptionId = Auth::user()->branch->subscription_id;
        $customFieldDefinitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        $printTemplates = PrintTemplate::where('subscription_id', $subscriptionId)
            ->where('type', 'cotizacion')
            ->whereHas('branches', function ($q) use ($quote) {
                $q->where('branches.id', $quote->branch_id);
            })
            ->select('id', 'name')
            ->get();

        return Inertia::render('Quote/Show', [
            'quote' => $quote,
            'activities' => $formattedActivities,
            'customFieldDefinitions' => $customFieldDefinitions,
            'printTemplates' => $printTemplates,
        ]);
    }

    public function updateStatus(Request $request, Quote $quote)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(QuoteStatus::class)],
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $quote->status->value;

        if ($newStatus === QuoteStatus::SALE_GENERATED->value && !$quote->transaction_id) {
            try {
                $this->createSaleTransaction($quote, Auth::user());
                return redirect()->back()->with('success', 'Venta generada automáticamente desde el cambio de estatus.');
            } catch (\Exception $e) {
                return redirect()->back()->with('error', 'Error al generar la venta: ' . $e->getMessage());
            }
        }

        if (
            $newStatus === QuoteStatus::CANCELLED->value &&
            $oldStatus === QuoteStatus::SALE_GENERATED->value &&
            $quote->transaction_id
        ) {
            DB::transaction(function () use ($quote) {
                $quote->load(['transaction.payments', 'items']);
                $transaction = $quote->transaction;

                if ($transaction && $transaction->status !== TransactionStatus::CANCELLED && $transaction->status !== TransactionStatus::REFUNDED) {
                    foreach ($quote->items as $item) {
                        if ($item->itemable_type == Product::class) {
                            $product = Product::find($item->itemable_id);
                            if ($product) $product->increment('current_stock', $item->quantity);
                        } elseif ($item->itemable_type == ProductAttribute::class) {
                            $variant = ProductAttribute::find($item->itemable_id);
                            if ($variant) $variant->increment('current_stock', $item->quantity);
                        }
                    }

                    $totalPaid = $transaction->payments->sum('amount');
                    $transaction->status = $totalPaid > 0 ? TransactionStatus::REFUNDED : TransactionStatus::CANCELLED;
                    $transaction->save();

                    if ($transaction->customer_id) {
                        $customer = Customer::find($transaction->customer_id);
                        if ($customer) {
                            $creditAmount = $transaction->subtotal - $transaction->total_discount + $transaction->total_tax;
                            $customer->increment('balance', $creditAmount);
                            $customer->balanceMovements()->create([
                                'transaction_id' => $transaction->id,
                                'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                                'amount' => $creditAmount,
                                'balance_after' => $customer->fresh()->balance,
                            ]);
                        }
                    }
                }
            });
        }

        $quote->update(['status' => $newStatus]);
        return redirect()->back()->with('success', 'Estatus de la cotización actualizado.');
    }

    public function newVersion(Quote $quote)
    {
        $newQuote = DB::transaction(function () use ($quote) {
            $newVersionNumber = ($quote->versions()->max('version_number') ?? $quote->version_number) + 1;

            $replicatedQuote = $quote->replicate()->fill([
                'parent_quote_id' => $quote->parent_quote_id ?? $quote->id,
                'version_number' => $newVersionNumber,
                'status' => QuoteStatus::DRAFT,
                'folio' => $quote->folio . '-V' . $newVersionNumber,
            ]);
            $replicatedQuote->save();

            foreach ($quote->items as $item) {
                $replicatedQuote->items()->create($item->toArray());
            }
            return $replicatedQuote;
        });

        return redirect()->route('quotes.edit', $newQuote->id);
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
        // CORRECCIÓN: Cargar 'product' también aquí
        $quote->load([
            'customer', 
            'branch.subscription',
            'items.itemable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Product::class => ['media'],
                    Service::class => ['media'],
                    ProductAttribute::class => ['product.media'], // <-- AQUÍ
                ]);
            }
        ]);

        $subscriptionId = Auth::user()->branch->subscription_id;
        $customFieldDefinitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        $printTemplate = null;
        if ($request->has('template_id')) {
            $printTemplate = PrintTemplate::find($request->input('template_id'));
        }

        return Inertia::render('Quote/Print', [
            'quote' => $quote,
            'customFieldDefinitions' => $customFieldDefinitions,
            'printTemplate' => $printTemplate,
        ]);
    }

    public function convertToSale(Request $request, Quote $quote)
    {
        if ($quote->status !== QuoteStatus::AUTHORIZED) {
            return redirect()->back()->with('error', 'Solo las cotizaciones autorizadas pueden convertirse en venta.');
        }
        if ($quote->transaction_id) {
            return redirect()->back()->with('error', 'Esta cotización ya tiene una venta asociada.');
        }

        try {
            $newTransaction = $this->createSaleTransaction($quote, Auth::user());

            return redirect()->route('quotes.show', $quote->id)
                ->with('success', 'Cotización convertida a venta con éxito. Folio de Venta: ' . $newTransaction->folio)
                ->with('transaction_id', $newTransaction->id);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al convertir: ' . $e->getMessage());
        }
    }

    private function generateSaleFolio($branchId): string
    {
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'LIKE', 'V-%')
            ->orderBy('id', 'desc') 
            ->first();

        $sequence = 1; 

        if ($lastTransaction) {
            $lastFolioNumber = (int) substr($lastTransaction->folio, 2);
            $sequence = $lastFolioNumber + 1;
        }

        return 'V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    private function getFormData()
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        return [
            'customers' => Customer::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->get(['id', 'name']),
            'products' => Product::where('branch_id', $user->branch_id)->with('productAttributes')->get(),
            'services' => Service::where('branch_id', $user->branch_id)->get(['id', 'name', 'base_price']),
            'customFieldDefinitions' => CustomFieldDefinition::where('subscription_id', $subscriptionId)->where('module', 'quotes')->get(),
        ];
    }

    private function createSaleTransaction(Quote $quote, $user)
    {
        return DB::transaction(function () use ($quote, $user) {
            $folio = $this->generateSaleFolio($user->branch_id);

            $transaction = Transaction::create([
                'folio' => $folio,
                'customer_id' => $quote->customer_id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'transactionable_id' => $quote->id,
                'transactionable_type' => Quote::class,
                'status' => TransactionStatus::PENDING,
                'channel' => TransactionChannel::QUOTE,
                'subtotal' => $quote->subtotal,
                'total_discount' => $quote->total_discount,
                'total_tax' => $quote->total_tax,
            ]);

            foreach ($quote->items as $quoteItem) {
                $transaction->items()->create([
                    'itemable_id' => $quoteItem->itemable_id,
                    'itemable_type' => $quoteItem->itemable_type,
                    'description' => $quoteItem->description,
                    'quantity' => $quoteItem->quantity,
                    'unit_price' => $quoteItem->unit_price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $quoteItem->line_total,
                ]);

                if ($quoteItem->itemable_type == Product::class) {
                    $product = Product::find($quoteItem->itemable_id);
                    if ($product) $product->decrement('current_stock', $quoteItem->quantity);
                } elseif ($quoteItem->itemable_type == ProductAttribute::class) {
                    $variant = ProductAttribute::find($quoteItem->itemable_id);
                    if ($variant) $variant->decrement('current_stock', $quoteItem->quantity);
                }
            }

            $quote->update([
                'status' => QuoteStatus::SALE_GENERATED,
                'transaction_id' => $transaction->id,
            ]);

            if ($quote->customer_id) {
                $customer = Customer::find($quote->customer_id);
                if ($customer) {
                    $debtAmount = $quote->total_amount;
                    $customer->decrement('balance', $debtAmount);

                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::CREDIT_SALE,
                        'amount' => -$debtAmount,
                        'balance_after' => $customer->fresh()->balance,
                    ]);
                }
            }

            return $transaction;
        });
    }

    private function validateCustomFields(Request $request)
    {
        $user = $request->user();
        $subscriptionId = $user->branch->subscription_id;
        $definitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        if ($definitions->isEmpty()) {
            return ['custom_fields' => []];
        }

        $rules = [];
        $messages = [];

        foreach ($definitions as $field) {
            $ruleKey = 'custom_fields.' . $field->key;
            $rules[$ruleKey] = ['nullable'];
            $messages["{$ruleKey}.*"] = "El campo {$field->name} es inválido.";

            switch ($field->type) {
                case 'text':
                case 'textarea':
                    $rules[$ruleKey][] = 'string';
                    $rules[$ruleKey][] = 'max:255';
                    break;
                case 'number':
                    $rules[$ruleKey][] = 'numeric';
                    break;
                case 'boolean':
                    $rules[$ruleKey][] = 'boolean';
                    break;
                case 'select':
                    $rules[$ruleKey][] = 'string';
                    if (!empty($field->options)) {
                        $rules[$ruleKey][] = Rule::in($field->options);
                    }
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

        return $request->validate([
            'custom_fields' => ['nullable', 'array'],
            ...$rules
        ], $messages);
    }
}