<?php

namespace App\Http\Controllers;

use App\Enums\QuoteStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Transaction;
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
            // --- INICIO DE CAMBIOS ---
            // 1. Solo mostrar cotizaciones "originales" (que no son hijas de otra)
            ->whereNull('parent_quote_id')
            // --- FIN DE CAMBIOS ---
            ->leftJoin('customers', 'quotes.customer_id', '=', 'customers.id')
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            })
            // --- INICIO DE CAMBIOS ---
            // 2. Cargar la cotización principal, sus versiones (hijas) y el cliente de esas versiones
            ->with(['customer:id,name', 'versions.customer:id,name'])
            // --- FIN DE CAMBIOS ---
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

            // --- INICIO: Validación de campos personalizados ---
            // Validamos los campos personalizados por separado
            $customFieldsData = $this->validateCustomFields($request);
            // Los fusionamos con los datos validados del FormRequest
            $validatedData = array_merge($validated, $customFieldsData);
            // --- FIN: Validación ---

            $lastQuote = Quote::where('branch_id', $user->branch_id)->latest('id')->first();
            $nextFolioNumber = $lastQuote ? (int) substr($lastQuote->folio, 4) + 1 : 1;
            $folio = 'COT-' . $nextFolioNumber;

            $quote = Quote::create(array_merge($validatedData, [ // <-- USAR $validatedData
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'folio' => $folio,
                'status' => QuoteStatus::DRAFT,
            ]));

            foreach ($validatedData['items'] as $item) { // <-- USAR $validatedData
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

            // --- INICIO: Validación de campos personalizados ---
            $customFieldsData = $this->validateCustomFields($request);
            $validatedData = array_merge($validated, $customFieldsData);
            // --- FIN: Validación ---

            $quote->update($validatedData); // <-- USAR $validatedData

            $quote->items()->delete(); // Simple: borrar y recrear
            foreach ($validatedData['items'] as $item) { // <-- USAR $validatedData
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

        $subscriptionId = Auth::user()->branch->subscription_id;
        $customFieldDefinitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        return Inertia::render('Quote/Show', [
            'quote' => $quote,
            'activities' => $formattedActivities,
            'customFieldDefinitions' => $customFieldDefinitions,
        ]);
    }

    public function updateStatus(Request $request, Quote $quote)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(QuoteStatus::class)],
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $quote->status->value;

        // Solo devolver stock si se CANCELA una cotización que YA generó venta
        if (
            $newStatus === QuoteStatus::CANCELLED->value &&
            $oldStatus === QuoteStatus::SALE_GENERATED->value && // Solo desde 'venta_generada'
            $quote->transaction_id
        ) {

            DB::transaction(function () use ($quote) {
                // 1. Cargar la transacción, sus pagos y los items
                $quote->load(['transaction.payments', 'items']);
                $transaction = $quote->transaction;

                if ($transaction && $transaction->status !== TransactionStatus::CANCELLED && $transaction->status !== TransactionStatus::REFUNDED) {

                    // 2. Devolver el stock (AHORA INCLUYE VARIANTES)
                    foreach ($quote->items as $item) {

                        if ($item->itemable_type == Product::class) {
                            // Caso 1: Producto Simple
                            $product = Product::find($item->itemable_id);
                            if ($product) {
                                $product->increment('current_stock', $item->quantity);
                            }
                        } elseif ($item->itemable_type == ProductAttribute::class) {
                            // Caso 2: Variante de Producto
                            $variant = ProductAttribute::find($item->itemable_id);
                            if ($variant) {
                                $variant->increment('current_stock', $item->quantity);
                            }
                        }
                    }

                    // 3. Actualizar la transacción a 'Cancelada' o 'Reembolsada'
                    $totalPaid = $transaction->payments->sum('amount');
                    $transaction->status = $totalPaid > 0 ? TransactionStatus::REFUNDED : TransactionStatus::CANCELLED;
                    $transaction->save();
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

    /**
     * Muestra una versión imprimible de la cotización.
     */
    public function print(Quote $quote): Response
    {
        // Cargar todas las relaciones necesarias para la plantilla
        $quote->load(['customer', 'items.itemable', 'branch.subscription']);

        $subscriptionId = Auth::user()->branch->subscription_id;
        $customFieldDefinitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        return Inertia::render('Quote/Print', [
            'quote' => $quote,
            'customFieldDefinitions' => $customFieldDefinitions,
        ]);
    }

    /**
     * Convierte una cotización autorizada en una transacción de venta.
     */
    public function convertToSale(Request $request, Quote $quote)
    {
        // 1. Validar que la cotización esté autorizada
        if ($quote->status !== QuoteStatus::AUTHORIZED) {
            return redirect()->back()->with('error', 'Solo se pueden convertir cotizaciones autorizadas.');
        }

        // 2. Validar que no se haya generado ya una venta
        if ($quote->transaction_id) {
            return redirect()->back()->with('error', 'Esta cotización ya ha sido convertida en una venta.');
        }

        $newTransaction = DB::transaction(function () use ($quote) {
            $user = Auth::user();

            // 3. Generar un nuevo folio de Venta
            $folio = $this->generateSaleFolio($user->branch_id);

            // 4. Crear la Transacción (Venta)
            $transaction = Transaction::create([
                'folio' => $folio,
                'customer_id' => $quote->customer_id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'cash_register_session_id' => null, // La venta se crea, pero se paga/asigna en caja después
                'transactionable_id' => $quote->id,
                'transactionable_type' => Quote::class,
                'status' => TransactionStatus::PENDING, // PENDIENTE de pago
                'channel' => TransactionChannel::QUOTE,
                'subtotal' => $quote->subtotal,
                'total_discount' => $quote->total_discount,
                'total_tax' => $quote->total_tax,
                'notes' => "Venta generada desde la cotización #{$quote->folio}.\n" . $quote->notes,
            ]);

            // 5. Copiar los items de la cotización a la transacción
            foreach ($quote->items as $quoteItem) {
                $transaction->items()->create([
                    'itemable_id' => $quoteItem->itemable_id,
                    'itemable_type' => $quoteItem->itemable_type, // <-- Esto ahora puede ser ProductAttribute::class
                    'description' => $quoteItem->description,
                    'quantity' => $quoteItem->quantity,
                    'unit_price' => $quoteItem->unit_price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $quoteItem->line_total,
                ]);

                // --- INICIO: LÓGICA DE DESCUENTO DE STOCK REFACTORIZADA ---
                
                if ($quoteItem->itemable_type == Product::class) {
                    // Caso 1: Producto Simple
                    $product = Product::find($quoteItem->itemable_id);
                    if ($product) {
                        $product->decrement('current_stock', $quoteItem->quantity);
                    }
                } elseif ($quoteItem->itemable_type == ProductAttribute::class) {
                    // Caso 2: Variante de Producto
                    $variant = ProductAttribute::find($quoteItem->itemable_id);
                    if ($variant) {
                        $variant->decrement('current_stock', $quoteItem->quantity);
                    }
                }
                // --- FIN: LÓGICA DE DESCUENTO DE STOCK ---
            }

            // 6. Actualizar la cotización
            $quote->update([
                'status' => QuoteStatus::SALE_GENERATED,
                'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });

        // 7. Redirigir de vuelta a la cotización con mensaje de éxito
        return redirect()->route('quotes.show', $quote->id)->with('success', "Venta #{$newTransaction->folio} generada con éxito.");
    }

    private function generateSaleFolio($branchId): string
    {
        // Buscar la última transacción para esta sucursal que siga el formato V-
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'LIKE', 'V-%')
            ->orderBy('id', 'desc') // Ordenar por ID para obtener la más reciente de forma fiable
            ->first();

        $sequence = 1; // Iniciar en 1 por defecto

        if ($lastTransaction) {
            // Extraer solo la parte numérica del folio anterior (ej. de "V-001" -> 1)
            $lastFolioNumber = (int) substr($lastTransaction->folio, 2);
            $sequence = $lastFolioNumber + 1;
        }

        // Formatear el nuevo folio con el prefijo y 3 ceros a la izquierda
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

    /**
     * Valida los campos personalizados dinámicamente.
     */
    private function validateCustomFields(Request $request)
    {
        $user = $request->user();
        $subscriptionId = $user->branch->subscription_id;
        $definitions = CustomFieldDefinition::where('subscription_id', $subscriptionId)
            ->where('module', 'quotes')
            ->get();

        if ($definitions->isEmpty()) {
            return ['custom_fields' => []]; // No hay campos para validar
        }

        $rules = [];
        $messages = [];

        foreach ($definitions as $field) {
            $ruleKey = 'custom_fields.' . $field->key;
            $rules[$ruleKey] = ['nullable'];
            $messages["{$ruleKey}.*"] = "El campo {$field->name} es inválido."; // Mensaje genérico

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
                    $rules[$ruleKey] = 'array'; // Sobrescribir 'nullable'
                    $rules["{$ruleKey}.*"] = ['string', Rule::in($field->options ?? [])];
                    break;
                case 'pattern':
                    $rules[$ruleKey] = 'array'; // Sobrescribir 'nullable'
                    $rules["{$ruleKey}.*"] = 'integer'; // Asume que el patrón es un array de enteros
                    break;
            }
        }

        // Validar solo los campos personalizados
        return $request->validate([
            'custom_fields' => ['nullable', 'array'], // Asegurarse que 'custom_fields' es un array
            ...$rules
        ], $messages);
    }
}
