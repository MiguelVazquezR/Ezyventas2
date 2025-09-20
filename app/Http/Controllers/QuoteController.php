<?php

namespace App\Http\Controllers;

use App\Enums\QuoteStatus;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $query = Quote::query()
            ->join('customers', 'quotes.customer_id', '=', 'customers.id')
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            })
            ->with('customer:id,name')
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
            
            $lastQuote = Quote::where('branch_id', $user->branch_id)->latest('id')->first();
            $nextFolioNumber = $lastQuote ? (int) substr($lastQuote->folio, 4) + 1 : 1;
            $folio = 'COT-' . $nextFolioNumber;

            $quote = Quote::create(array_merge($validated, [
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'folio' => $folio,
                'status' => QuoteStatus::DRAFT,
            ]));

            foreach ($validated['items'] as $item) {
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
            $quote->update($validated);

            $quote->items()->delete(); // Simple: borrar y recrear
            foreach ($validated['items'] as $item) {
                $quote->items()->create($item);
            }
        });

        return redirect()->route('quotes.index')->with('success', 'Cotización actualizada con éxito.');
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
}