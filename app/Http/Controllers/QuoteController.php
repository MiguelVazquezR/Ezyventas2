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
use Illuminate\Validation\Rule;
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

    public function show(Quote $quote): Response
    {
        // Cargar todas las relaciones necesarias para la vista
        $quote->load(['customer', 'user', 'items.itemable', 'parent.versions', 'versions', 'activities.causer']);

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

        return Inertia::render('Quote/Show', [
            'quote' => $quote,
            'activities' => $formattedActivities,
        ]);
    }

    public function updateStatus(Request $request, Quote $quote)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(QuoteStatus::class)],
        ]);
        $quote->update(['status' => $validated['status']]);
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

    /**
     * Muestra una versión imprimible de la cotización.
     */
    public function print(Quote $quote): Response
    {
        // Cargar todas las relaciones necesarias para la plantilla
        $quote->load(['customer', 'items.itemable', 'branch.subscription']);

        return Inertia::render('Quote/Print', [
            'quote' => $quote,
        ]);
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
