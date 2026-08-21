<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\AcceptRejectInvoiceAction;
use App\Actions\Billing\CancelInvoiceAction;
use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\UpdateInvoiceAction;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AcceptRejectRequest;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Requests\Billing\UpdateInvoiceRequest;
use App\Models\Billing\AcceptRejectResponse;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceVariant;
use App\Models\Transaction;
use App\Services\Billing\SatConsultationService;
use App\Services\SW\SWUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:invoices.access', only: ['index']),
            new Middleware('can:invoices.create', only: ['create', 'store', 'salesSearch', 'salesShow']),
            new Middleware('can:invoices.see_details', only: ['show']),
            new Middleware('can:invoices.edit', only: ['edit', 'update']),
            new Middleware('can:invoices.cancel', only: ['cancel', 'acceptReject', 'acceptRejectHistory']),
            new Middleware('can:invoices.settings.access', only: ['settings', 'dashboard']),
        ];
    }

    /**
     * Billing dashboard — KPIs & stamp usage overview.
     */
    public function dashboard(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $fiscalProfiles = $subscription
            ? $subscription->fiscalProfiles()->with('pacAccount')->orderBy('created_at', 'desc')->get()
            : collect();

        $draftInvoices = Invoice::where('branch_id', $user->branch_id)->draft()->count();
        $certifiedInvoices = Invoice::where('branch_id', $user->branch_id)->certified()->count();
        $cancelationPendingInvoices = Invoice::where('branch_id', $user->branch_id)
            ->where('status', \App\Enums\InvoiceStatus::CANCELATION_PENDING)
            ->count();
        $canceledInvoices = Invoice::where('branch_id', $user->branch_id)->canceled()->count();

        // Per-fiscal-profile KPIs with live stamp balances
        $swUserService = app(SWUserService::class);
        $fiscalProfilesData = $fiscalProfiles->map(function ($profile) use ($swUserService, $user, $request) {
            [$balance, $balanceError] = $profile->stampBalance($swUserService);

            $invoiceQuery = Invoice::where('branch_id', $user->branch_id)
                ->where('fiscal_profile_id', $profile->id);

            return [
                'id'                      => $profile->id,
                'rfc'                     => $profile->rfc,
                'razon_social'            => $profile->razon_social,
                'is_active'               => $profile->is_active,
                'account_status'          => $profile->isLinkedToPac() ? 'active' : 'pending',
                'balance'                 => $balance,
                'balanceError'            => $balanceError,
                'draftCount'              => (clone $invoiceQuery)->draft()->count(),
                'certifiedCount'          => (clone $invoiceQuery)->certified()->count(),
                'cancelationPendingCount' => (clone $invoiceQuery)->where('status', \App\Enums\InvoiceStatus::CANCELATION_PENDING)->count(),
                'canceledCount'           => (clone $invoiceQuery)->canceled()->count(),
            ];
        });

        return Inertia::render('Billing/Dashboard/Index', [
            'fiscalProfiles'             => $fiscalProfilesData,
            'draftInvoices'              => $draftInvoices,
            'certifiedInvoices'          => $certifiedInvoices,
            'cancelationPendingInvoices' => $cancelationPendingInvoices,
            'canceledInvoices'           => $canceledInvoices,
        ]);
    }

    /**
     * List all invoices for the current branch.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $query = Invoice::query()
            ->where('branch_id', $user->branch_id)
            ->with(['customer:id,name,company_name', 'fiscalProfile:id,razon_social,rfc']);

        // Search across folio, receiver name, receiver RFC, UUID, and customer
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('folio', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('receiver_legal_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('receiver_rfc', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('uuid', 'LIKE', "%{$searchTerm}%")
                  ->orWhereHas('customer', function ($cq) use ($searchTerm) {
                      $cq->where('name', 'LIKE', "%{$searchTerm}%")
                         ->orWhere('company_name', 'LIKE', "%{$searchTerm}%");
                  });
            });
        }

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by payment method (PUE / PPD) if provided
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        $query->orderBy(
            $request->input('sortField', 'created_at'),
            $request->input('sortOrder', 'desc'),
        );

        // Get fiscal profiles for the filter dropdown
        $fiscalProfiles = $subscription?->fiscalProfiles()->active()
            ->get(['id', 'rfc', 'razon_social']) ?? collect();

        return Inertia::render('Billing/Invoices/Index', [
            'invoices'           => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters'            => $request->only(['search', 'status', 'fiscal_profile_id', 'payment_method', 'sortField', 'sortOrder']),
            'fiscalProfiles'     => $fiscalProfiles,
            'hasFiscalProfiles'  => $subscription?->fiscalProfiles()->active()->exists() ?? false,
        ]);
    }

    /**
     * Show the invoice creation form.
     */
    public function create(): Response
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $billingEnabled = $subscription?->billingEnabled() ?? false;

        // Only fetch profiles when billing is enabled and there are profiles
        // ready for invoicing (linked to an active PAC account).
        $fiscalProfiles = $billingEnabled
            ? ($subscription?->fiscalProfiles()->with('pacAccount:id,account_type')->readyForInvoicing()->get(['id', 'pac_account_id', 'rfc', 'razon_social', 'regimen_fiscal', 'postal_code', 'manifest_signed_at', 'certificate_number']) ?? [])
            : [];

        $hasFiscalProfiles = $billingEnabled
            && ($subscription?->fiscalProfiles()->readyForInvoicing()->exists() ?? false);

        // Facturas certificadas PPD para el buscador de "Documentos relacionados" (CFDI de pago)
        $ppdInvoices = $this->ppdInvoicesForBranch($user->branch_id);

        return Inertia::render('Billing/Invoices/Create', [
            'customers'            => $user->branch->customers()->orderBy('name')->get(['id', 'name', 'company_name', 'tax_id', 'tax_regime', 'address']),
            'fiscalProfiles'       => $fiscalProfiles,
            'hasFiscalProfiles'    => $hasFiscalProfiles,
            'ppdInvoices'          => $ppdInvoices,
            'products'             => Product::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))->orderBy('name')->get(['id', 'name', 'sku', 'selling_price', 'sat_product_code', 'sat_unit_code']),
            'services'             => Service::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))->orderBy('name')->get(['id', 'name', 'base_price', 'sat_product_code', 'sat_unit_code']),
        ]);
    }

    /**
     * Store a newly created CFDI invoice.
     */
    public function store(StoreInvoiceRequest $request, CreateInvoiceAction $action): RedirectResponse
    {
        try {
            $draft = $request->boolean('draft', false);
            $invoice = $action->execute(
                $request->validated(),
                Auth::user(),
                $draft,
            );

            // Sin timbres suficientes: la factura YA quedó guardada como
            // prefactura (borrador); se avisa con claridad en vez de parecer
            // un error. El usuario puede timbrarla más tarde.
            if (! empty($invoice->stamp_blocked)) {
                return redirect()->route('billing.invoices.show', $invoice->id)
                    ->with('warning', $invoice->stamp_blocked . ' Tu factura se guardó como prefactura y puedes timbrarla más tarde cuando tengas timbres disponibles.');
            }

            $message = $draft
                ? 'Prefactura guardada. Puedes timbrarla cuando estés listo.'
                : 'Factura creada y timbrada correctamente.';

            return redirect()->route('billing.invoices.show', $invoice->id)
                ->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Show the form for editing a draft invoice (prefactura).
     */
    public function edit(Invoice $invoice): Response|RedirectResponse
    {
        if (! $invoice->isEditable()) {
            return redirect()->route('billing.invoices.show', $invoice->id)
                ->with('error', 'Solo las prefacturas pueden editarse. Una factura timbrada no se puede modificar.');
        }

        $invoice->load(['items', 'customer', 'fiscalProfile', 'transaction.customer']);

        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $fiscalProfiles = $subscription?->fiscalProfiles()->with('pacAccount:id,account_type')->readyForInvoicing()
            ->get(['id', 'pac_account_id', 'rfc', 'razon_social', 'regimen_fiscal', 'postal_code', 'manifest_signed_at', 'certificate_number']) ?? collect();

        // Facturas certificadas PPD para el buscador de "Documentos relacionados" (CFDI de pago)
        $ppdInvoices = $this->ppdInvoicesForBranch($user->branch_id);

        return Inertia::render('Billing/Invoices/Edit', [
            'invoice'          => $invoice,
            'customers'        => $user->branch->customers()->orderBy('name')->get(['id', 'name', 'company_name', 'tax_id', 'tax_regime', 'address']),
            'fiscalProfiles'   => $fiscalProfiles,
            'hasFiscalProfiles' => $fiscalProfiles->isNotEmpty(),
            'ppdInvoices'      => $ppdInvoices,
            'products'         => Product::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))->orderBy('name')->get(['id', 'name', 'sku', 'selling_price', 'sat_product_code', 'sat_unit_code']),
            'services'         => Service::whereHas('branches', fn($q) => $q->where('branches.id', $user->branch_id))->orderBy('name')->get(['id', 'name', 'base_price', 'sat_product_code', 'sat_unit_code']),
        ]);
    }

    /**
     * Update a draft invoice (prefactura).
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice, UpdateInvoiceAction $action): RedirectResponse
    {
        try {
            $action->execute($request->validated(), $invoice, Auth::user());

            return redirect()->route('billing.invoices.show', $invoice->id)
                ->with('success', 'Prefactura actualizada correctamente.');
        } catch (\RuntimeException $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display a single invoice with its items and customer.
     */
    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items', 'customer', 'branch', 'fiscalProfile', 'transaction:id,folio,status']);

        // ── Relación PPD ↔ PAGO ──
        // Factura PPD (I + PPD): busca los CFDI de Pago certificados que la
        // cubren y calcula cuánto resta por pagar.
        $relatedPayments = collect();
        $remainingToPay = null;

        if ($invoice->tipo_comprobante === 'I' && $invoice->payment_method === 'PPD' && $invoice->uuid) {
            $pagoInvoices = Invoice::forBranch($invoice->branch_id)
                ->certified()
                ->where('tipo_comprobante', 'P')
                ->whereNull('canceled_at')
                ->get(['id', 'folio', 'uuid', 'pago_fecha', 'pago_monto', 'pago_documentos']);

            $totalPaid = 0.0;
            $relatedPayments = $pagoInvoices
                ->map(function (Invoice $pago) use ($invoice, &$totalPaid) {
                    $matched = collect($pago->pago_documentos ?? [])
                        ->filter(fn (array $doc) =>
                            (int) ($doc['invoice_id'] ?? 0) === (int) $invoice->id
                            || ((string) ($doc['uuid'] ?? '') !== '' && strcasecmp((string) $doc['uuid'], (string) $invoice->uuid) === 0))
                        ->values();

                    if ($matched->isEmpty()) {
                        return null;
                    }

                    $totalPaid += (float) $matched->sum('imp_pagado');

                    return [
                        'id'         => $pago->id,
                        'folio'      => $pago->folio,
                        'uuid'       => $pago->uuid,
                        'pago_fecha' => $pago->pago_fecha?->toIso8601String(),
                        'pago_monto' => (float) $pago->pago_monto,
                        'documentos' => $matched->map(fn (array $doc) => [
                            'num_parcialidad'    => $doc['num_parcialidad'] ?? null,
                            'imp_saldo_ant'      => (float) ($doc['imp_saldo_ant'] ?? 0),
                            'imp_pagado'         => (float) ($doc['imp_pagado'] ?? 0),
                            'imp_saldo_insoluto' => (float) ($doc['imp_saldo_insoluto'] ?? 0),
                        ]),
                    ];
                })
                ->filter()
                ->values();

            $remainingToPay = max(0.0, round((float) $invoice->total - $totalPaid, 2));
        }

        // CFDI de Pago (P): resuelve cada documento relacionado a su factura
        // PPD para poder enlazarla desde la vista.
        $relatedPpdInvoices = collect();

        if ($invoice->tipo_comprobante === 'P') {
            $docs = collect($invoice->pago_documentos ?? []);
            $ppdIds = $docs->pluck('invoice_id')->filter()->unique()->values();

            $ppdMap = $ppdIds->isNotEmpty()
                ? Invoice::forBranch($invoice->branch_id)
                    ->whereIn('id', $ppdIds)
                    ->get(['id', 'series', 'folio', 'uuid', 'status', 'total'])
                    ->keyBy('id')
                : collect();

            // Fallback por UUID: documentos ligados solo con UUID (facturas
            // creadas antes de guardar invoice_id o escritas a mano) también
            // deben enlazar a su factura PPD.
            $ppdByUuid = collect();
            $uuids = $docs->pluck('uuid')->filter()->unique()->values();
            if ($uuids->isNotEmpty()) {
                $ppdByUuid = Invoice::forBranch($invoice->branch_id)
                    ->whereIn('uuid', $uuids)
                    ->get(['id', 'series', 'folio', 'uuid', 'status', 'total'])
                    ->keyBy(fn (Invoice $i) => strtolower((string) $i->uuid));
            }

            $relatedPpdInvoices = $docs->map(function (array $doc) use ($ppdMap, $ppdByUuid) {
                $ppd = $ppdMap->get((int) ($doc['invoice_id'] ?? 0))
                    ?? $ppdByUuid->get(strtolower((string) ($doc['uuid'] ?? '')));

                return [
                    'doc' => [
                        'uuid'               => $doc['uuid'] ?? '',
                        'folio'              => $doc['folio'] ?? '',
                        'num_parcialidad'    => $doc['num_parcialidad'] ?? null,
                        'imp_saldo_ant'      => (float) ($doc['imp_saldo_ant'] ?? 0),
                        'imp_pagado'         => (float) ($doc['imp_pagado'] ?? 0),
                        'imp_saldo_insoluto' => (float) ($doc['imp_saldo_insoluto'] ?? 0),
                    ],
                    'invoice' => $ppd ? [
                        'id'     => $ppd->id,
                        'series' => $ppd->series,
                        'folio'  => $ppd->folio,
                        'uuid'   => $ppd->uuid,
                        'status' => $ppd->status->value,
                        'total'  => (float) $ppd->total,
                    ] : null,
                ];
            })->values();
        }

        return Inertia::render('Billing/Invoices/Show', [
            'invoice'            => $invoice,
            'relatedPayments'    => $relatedPayments,
            'remainingToPay'     => $remainingToPay,
            'relatedPpdInvoices' => $relatedPpdInvoices,
        ]);
    }

    /**
     * Facturas certificadas PPD (I + PPD) de la sucursal, enriquecidas para el
     * buscador de documentos del CFDI de Pago: saldo restante (total − pagos ya
     * aplicados), siguiente número de parcialidad y venta POS vinculada.
     */
    private function ppdInvoicesForBranch(int $branchId): \Illuminate\Support\Collection
    {
        $ppdInvoices = Invoice::forBranch($branchId)
            ->certified()
            ->where('payment_method', 'PPD')
            ->where('tipo_comprobante', 'I')
            ->whereNull('canceled_at')
            ->with('transaction:id,folio')
            ->orderByDesc('issued_at')
            ->get(['id', 'transaction_id', 'fiscal_profile_id', 'customer_id', 'series', 'folio', 'uuid', 'total', 'currency', 'receiver_rfc', 'receiver_legal_name', 'receiver_tax_regime', 'receiver_postal_code', 'issued_at']);

        // Pagos ya aplicados por factura PPD (desde los CFDI de Pago certificados).
        $paidByPpd = [];
        $pagoInvoices = Invoice::forBranch($branchId)
            ->certified()
            ->where('tipo_comprobante', 'P')
            ->whereNull('canceled_at')
            ->get(['pago_documentos']);

        foreach ($pagoInvoices as $pago) {
            foreach ($pago->pago_documentos ?? [] as $doc) {
                $ppdId = (int) ($doc['invoice_id'] ?? 0);
                if ($ppdId > 0) {
                    $paidByPpd[$ppdId][] = (float) ($doc['imp_pagado'] ?? 0);
                }
            }
        }

        return $ppdInvoices->map(function (Invoice $invoice) use ($paidByPpd) {
            $paid = array_sum($paidByPpd[$invoice->id] ?? []);
            $invoice->setAttribute('remaining', max(0.0, round((float) $invoice->total - $paid, 2)));
            $invoice->setAttribute('num_parcialidad', count($paidByPpd[$invoice->id] ?? []) + 1);
            $invoice->setAttribute('sale_folio', $invoice->transaction?->folio);

            return $invoice;
        })->values();
    }

    /**
     * Search POS sales available for invoicing (JSON — used by the invoice form).
     *
     * Returns completed or delivered-unpaid sales that are fully paid, plus
     * credit sales (pending) — facturable como PPD — that belong to the current
     * branch and have no linked invoice.
     */
    public function salesSearch(Request $request): JsonResponse
    {
        $user = Auth::user();
        $search = trim((string) $request->input('search', ''));

        // Progressive loading: the form requests pages of 10 rows.
        $limit = (int) $request->input('limit', 10);
        $limit = min(max($limit, 1), 50);
        $offset = (int) $request->input('offset', 0);

        $sales = Transaction::query()
            ->where('branch_id', $user->branch_id)
            ->whereIn('status', [TransactionStatus::COMPLETED, TransactionStatus::DELIVERED_UNPAID, TransactionStatus::PENDING])
            ->where('channel', '!=', TransactionChannel::BALANCE_PAYMENT)
            ->uninvoiced()
            ->with(['customer:id,name'])
            ->withSum('payments as total_paid_sum', 'amount')
            ->when($search !== '', function ($query) use ($search) {
                // Tokenized matching: every word must match the folio or the
                // customer name, so partial/typo queries still find sales.
                $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
                $query->where(function ($query) use ($terms) {
                    foreach ($terms as $term) {
                        $query->where(function ($sub) use ($term) {
                            $sub->where('folio', 'LIKE', "%{$term}%")
                                ->orWhereHas('customer', fn ($q) => $q->where('name', 'LIKE', "%{$term}%"));
                        });
                    }
                });
            })
            ->orderByDesc('created_at')
            ->skip($offset)
            ->take($limit)
            ->get()
            // Las ventas a crédito (pendiente) se facturan como PPD aunque no
            // estén liquidadas; el resto debe estar pagado al 100 %.
            ->filter(fn (Transaction $transaction) =>
                $transaction->status === TransactionStatus::PENDING
                || (float) $transaction->total - (float) $transaction->total_paid_sum <= 0.01)
            ->map(fn (Transaction $transaction) => [
                'id'            => $transaction->id,
                'folio'         => $transaction->folio,
                'status'        => $transaction->status->value,
                'total'         => (float) $transaction->total,
                'remaining_due' => (float) $transaction->remaining_due,
                'customer_name' => $transaction->customer?->name,
                'created_at'    => $transaction->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($sales);
    }

    /**
     * Full POS sale detail in the shape the invoice form needs (JSON).
     *
     * Items come with SAT catalog codes resolved from the itemable
     * (Product, Service or ProductAttribute), and payments carry the
     * payment method for the FormaPago auto-fill.
     */
    public function salesShow(Transaction $transaction): JsonResponse
    {
        $user = Auth::user();

        if ($transaction->branch_id !== $user->branch_id) {
            abort(404);
        }

        if (! in_array($transaction->status, [TransactionStatus::COMPLETED, TransactionStatus::DELIVERED_UNPAID, TransactionStatus::PENDING], true)) {
            abort(422, 'Esta venta no se puede facturar porque no está completada.');
        }

        if ($transaction->invoiced) {
            abort(422, 'Esta venta ya tiene una factura relacionada.');
        }

        // Las ventas a crédito (pendiente) se facturan como PPD aunque falte
        // saldo por cobrar; el resto debe estar liquidada al 100 %.
        if ($transaction->status !== TransactionStatus::PENDING && $transaction->remaining_due > 0.01) {
            abort(422, 'Esta venta tiene pagos pendientes y no se puede facturar.');
        }

        $transaction->load([
            'customer:id,name,company_name,tax_id,tax_regime,address,fiscal_address',
            'items.itemable',
            'payments',
        ]);

        // Service-order sales keep their line items in service_order_items
        // (not in transaction_items), so the invoice concepts are built from
        // the linked order. Custom (typed) services have no catalog record.
        $sourceItems = $transaction->items;
        $isServiceOrder = false;

        if ($transaction->channel === TransactionChannel::SERVICE_ORDER) {
            $transaction->load('transactionable.items.itemable');
            if ($transaction->transactionable instanceof ServiceOrder) {
                $isServiceOrder = true;
                $sourceItems = $transaction->transactionable->items;
            }
        }

        // Custom services have no SAT codes on the catalog — use a generic
        // ClaveProdServ/ClaveUnidad so the concept stays valid for the PAC.
        $fallbackProductCode = $isServiceOrder ? '01010101' : '';
        $fallbackUnitCode = $isServiceOrder ? 'E48' : 'H87';

        $items = $sourceItems->map(function ($item) use ($fallbackProductCode, $fallbackUnitCode) {
            $itemable = $item->itemable;
            $catalogId = null;
            $catalogType = null;

            // Variants (ProductAttribute/ServiceVariant) inherit the SAT codes
            // from their parent catalog record.
            if ($itemable instanceof ProductAttribute) {
                $itemable = $itemable->product;
            } elseif ($itemable instanceof ServiceVariant) {
                $itemable = $itemable->service;
            }

            if ($itemable instanceof Product) {
                $catalogId = $itemable->id;
                $catalogType = 'product';
            } elseif ($itemable instanceof Service) {
                $catalogId = $itemable->id;
                $catalogType = 'service';
            }

            $satProductCode = (string) ($itemable->sat_product_code ?? '');
            $satUnitCode = (string) ($itemable->sat_unit_code ?? '');

            return [
                'description'       => $item->description,
                'quantity'          => (float) $item->quantity,
                'unit_price'        => (float) $item->unit_price,
                'discount_amount'   => (float) ($item->discount_amount ?? 0),
                'sat_product_code'  => $satProductCode !== '' ? $satProductCode : $fallbackProductCode,
                'sat_unit_code'     => $satUnitCode !== '' ? $satUnitCode : $fallbackUnitCode,
                'sku'               => $itemable->sku ?? null,
                'catalog_id'        => $catalogId,
                'catalog_type'      => $catalogType,
            ];
        });

        return response()->json([
            'id'            => $transaction->id,
            'folio'         => $transaction->folio,
            'status'        => $transaction->status->value,
            'total'         => (float) $transaction->total,
            'remaining_due' => (float) $transaction->remaining_due,
            'created_at'    => $transaction->created_at?->toIso8601String(),
            'customer'   => $transaction->customer ? [
                'id'             => $transaction->customer->id,
                'name'           => $transaction->customer->name,
                'company_name'   => $transaction->customer->company_name,
                'tax_id'         => $transaction->customer->tax_id,
                'tax_regime'     => $transaction->customer->tax_regime,
                'fiscal_address' => $transaction->customer->fiscal_address,
                'address'        => $transaction->customer->address,
            ] : null,
            'items'      => $items->values(),
            'payments'   => $transaction->payments->map(fn ($payment) => [
                'method' => $payment->payment_method->value ?? (string) $payment->payment_method,
                'amount' => (float) $payment->amount,
            ])->values(),
        ]);
    }

    /**
     * Render a print-friendly CFDI 4.0 invoice view (Inertia page).
     *
     * The user prints/saves as PDF via the browser (Ctrl+P).
     * No server-side PDF generation is used.
     */
    public function pdf(Invoice $invoice): Response
    {
        $invoice->load([
            'items',
            'customer',
            'branch.subscription.media',
            'fiscalProfile',
            'transaction:id,folio',
        ]);

        // ── Timbre data now read directly from model columns (saved at stamp time) ──
        $timbre = $this->extractTimbreData($invoice);

        // ── Comprobante data from XML root — only needed when XML is available ──
        $comprobante = $this->extractComprobanteData($invoice);

        // ── Retrieve company logo — prefer fiscal profile logo, fallback to subscription logo ──
        $logoUrl = $invoice->fiscalProfile?->getFirstMediaUrl('company_logo') ?: null;

        if (! $logoUrl) {
            $subscription = $invoice->branch?->subscription;
            if ($subscription) {
                $logoUrl = $subscription->getFirstMediaUrl('company_logo') ?: null;
            }
        }

        // ── QR code as data URI from base64 stored at stamp time ──
        $qrCodeSrc = $invoice->qr_code_base64
            ? 'data:image/png;base64,' . $invoice->qr_code_base64
            : null;

        return Inertia::render('Billing/Invoices/Pdf', [
            'invoice'           => $invoice,
            'timbre'            => $timbre,
            'comprobante'       => $comprobante,
            'qrCodeSrc'         => $qrCodeSrc,
            'subtotal'          => (float) $invoice->subtotal,
            'discountTotal'     => (float) $invoice->discount_total,
            'taxesTotal'        => (float) $invoice->taxes_total,
            'retainedTotal'     => (float) $invoice->retained_taxes_total,
            'total'             => (float) $invoice->total,
            'groupedTransfers'  => $this->groupTaxesByType($invoice),
            'groupedRetentions' => $this->groupRetentionsByType($invoice),
            'logoUrl'           => $logoUrl,
        ]);
    }

    /**
     * Download the CFDI XML file for a certified invoice.
     */
    public function downloadXml(Invoice $invoice): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $invoice->xml_url) {
            return redirect()->back()->with('error', 'Esta factura no tiene un archivo XML disponible.');
        }

        $path = \Illuminate\Support\Facades\Storage::disk('public')->path($invoice->xml_url);

        if (! file_exists($path)) {
            return redirect()->back()->with('error', 'El archivo XML no se encontró en el servidor.');
        }

        $filename = ($invoice->uuid ?: $invoice->folio) . '.xml';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    /**
     * Extract timbre fiscal data directly from model columns.
     *
     * All timbre fields are saved at stamp time from the SW Sapien JSON
     * response — no XML parsing needed.
     */
    private function extractTimbreData(Invoice $invoice): array
    {
        return [
            'uuid'               => $invoice->uuid ?: '—',
            'fecha_timbrado'     => $invoice->fecha_timbrado ?: '—',
            'rfc_prov_certif'    => $invoice->rfc_prov_certif ?: '—',
            'sello_cfd'          => $invoice->sello_cfdi ?: '—',
            'no_certificado_sat' => $invoice->no_certificado_sat ?: '—',
            'sello_sat'          => $invoice->sello_sat ?: '—',
            'cadena_original'    => $invoice->cadena_original_sat ?: '—',
        ];
    }

    /**
     * Extract comprobante-level attributes from the stored CFDI XML.
     *
     * Returns NoCertificado (emisor CSD), Sello (emisor), TipoDeComprobante,
     * Fecha, and LugarExpedicion from the root cfdi:Comprobante node.
     */
    private function extractComprobanteData(Invoice $invoice): array
    {
        $defaults = [
            'no_certificado'      => '—',
            'sello'               => '—',
            'tipo_de_comprobante' => 'I',
            'fecha'               => '—',
            'lugar_expedicion'    => '—',
        ];

        if (! $invoice->xml_url) {
            return $defaults;
        }

        $xmlContent = \Illuminate\Support\Facades\Storage::disk('public')->get($invoice->xml_url);
        if (! $xmlContent) {
            return $defaults;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();

        if (! $xml) {
            return $defaults;
        }

        $attrs = $xml->attributes();

        return [
            'no_certificado'      => (string) ($attrs['NoCertificado'] ?? '—'),
            'sello'               => (string) ($attrs['Sello'] ?? '—'),
            'tipo_de_comprobante' => (string) ($attrs['TipoDeComprobante'] ?? 'I'),
            'fecha'               => (string) ($attrs['Fecha'] ?? '—'),
            'lugar_expedicion'    => (string) ($attrs['LugarExpedicion'] ?? '—'),
        ];
    }

    /**
     * Group transferred taxes (traslados) by Impuesto + TipoFactor + TasaOCuota
     * for the global Impuestos summary in the PDF.
     */
    private function groupTaxesByType(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            if ($item->objeto_imp !== '02' || (float) $item->tax_amount <= 0) {
                continue;
            }

            $key = ($item->tax_type ?: '002') . '|Tasa|' . number_format((float) ($item->tax_rate ?: 0.16), 6, '.', '');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'impuesto'   => $item->tax_type ?: '002',
                    'tipoFactor' => 'Tasa',
                    'tasaOCuota' => (float) ($item->tax_rate ?: 0.16),
                    'base'       => 0.0,
                    'importe'    => 0.0,
                ];
            }

            $base = round((float) $item->subtotal - (float) $item->discount_amount, 2);
            $groups[$key]['base']    = round($groups[$key]['base'] + $base, 2);
            $groups[$key]['importe'] = round($groups[$key]['importe'] + (float) $item->tax_amount, 2);
        }

        return array_values($groups);
    }

    /**
     * Group retained taxes (retenciones) by Impuesto for the PDF summary.
     *
     * Handles both the multi-retention array (retentions JSON column)
     * and legacy single retention fields (retained_tax_type/amount).
     */
    private function groupRetentionsByType(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            $allRetentions = [];

            // Multi-retention array (new format — takes precedence)
            if (! empty($item->retentions) && is_array($item->retentions)) {
                foreach ($item->retentions as $ret) {
                    $retType   = $ret['type'] ?? $ret['impuesto'] ?? null;
                    $retAmount = (float) ($ret['amount'] ?? $ret['importe'] ?? 0);
                    $retRate   = (float) ($ret['rate'] ?? 0);
                    if ($retType && $retAmount > 0) {
                        $allRetentions[] = [
                            'impuesto'   => $retType,
                            'importe'    => $retAmount,
                            'tasaOCuota' => $retRate,
                        ];
                    }
                }
            }

            // Legacy single retention field — only used when no multi-retention array
            if (empty($allRetentions) && $item->retained_tax_type && (float) $item->retained_tax_amount > 0) {
                $allRetentions[] = [
                    'impuesto'   => $item->retained_tax_type,
                    'importe'    => (float) $item->retained_tax_amount,
                    'tasaOCuota' => (float) ($item->retained_tax_rate ?: 0),
                ];
            }

            foreach ($allRetentions as $ret) {
                $key = $ret['impuesto'];

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'impuesto'   => $ret['impuesto'],
                        'importe'    => 0.0,
                        'tasaOCuota' => $ret['tasaOCuota'] ?? 0.0,
                    ];
                }

                $groups[$key]['importe'] = round($groups[$key]['importe'] + $ret['importe'], 2);
            }
        }

        return array_values($groups);
    }

    /**
     * Display all fiscal profiles with pagination, search, sorting,
     * CSD certificate status, and live stamp balance per profile.
     */
    public function settings(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $query = $subscription->fiscalProfiles()->with('pacAccount');

        // Search across RFC and razón social
        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('rfc', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('razon_social', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Sorting — only allow known columns; fall back to created_at if sortField is empty or invalid
        $allowedSortFields = ['created_at', 'rfc', 'razon_social', 'regimen_fiscal', 'is_active', 'manifest_signed_at'];
        $sortField = $request->input('sortField');
        $sortField = in_array($sortField, $allowedSortFields, true) ? $sortField : 'created_at';
        $sortOrder = $request->input('sortOrder') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortOrder);

        $paginated = $query->paginate($request->input('rows', 20))->withQueryString();

        // Enrich each profile with CSD status and live stamp balance
        $swUserService = app(SWUserService::class);
        foreach ($paginated as $profile) {
            $profile->csd_status = $profile->certificate_number ? 'Activo' : 'Faltante';
            $profile->stamps_available = null;
            $profile->stamps_assigned  = null;
            $profile->stamps_used      = null;
            $profile->stamps_local     = false;
            $pacAccount = $profile->pacAccount;

            // The account type is administrative — hidden from the client payload.
            $pacAccount?->makeHidden(['account_type']);

            [$balance] = $profile->stampBalance($swUserService);
            if ($balance) {
                $profile->stamps_available = $balance['stampsBalance'] ?? null;
                $profile->stamps_assigned  = $balance['stampsAssigned'] ?? null;
                $profile->stamps_used      = $balance['stampsUsed'] ?? null;
                $profile->stamps_local     = (bool) ($balance['local'] ?? false);
            }
        }

        $ourBankAccounts = \App\Models\BankAccount::whereHas('branches', function ($q) {
            $q->where('branch_id', 1)->where('is_favorite', true);
        })->get();

        // New fiscal profiles are always linked to a shared PAC account, so
        // the step wizard always shows 2 steps (no manifest) for shared accounts.
        return Inertia::render('Billing/Settings/Index', [
            'fiscalProfiles'        => $paginated,
            'filters'               => $request->only(['search', 'sortField', 'sortOrder']),
            'ourBankAccounts'       => $ourBankAccounts,
        ]);
    }

    /**
     * Cancel a CFDI invoice (fiscal cancellation).
     */
    public function cancel(Invoice $invoice, CancelInvoiceRequest $request, CancelInvoiceAction $action): RedirectResponse
    {
        $result = $action->execute(
            $invoice,
            $request->validated('cancellation_reason'),
            $request->validated('substitution_uuid'),
        );

        if ($result['status'] === 'pending_acceptance') {
            return redirect()->route('billing.invoices.show', $invoice->id)
                ->with('warning', $result['message']);
        }

        return redirect()->route('billing.invoices.show', $invoice->id)
            ->with('success', $result['message']);
    }

    /**
     * Verify the cancelation status of a CFDI that requires receiver acceptance.
     * Queries the SAT public consultation service.
     */
    public function checkCancelationStatus(Invoice $invoice, SatConsultationService $satService): RedirectResponse
    {
        if ($invoice->status->value !== 'cancelacion_pendiente') {
            return redirect()->back()
                ->with('error', 'Esta factura no tiene una cancelación pendiente de aprobación.');
        }

        try {
            $satResult = $satService->consult($invoice);
            $result = $satService->applyResult($invoice, $satResult);

            $messages = [
                'canceled' => 'La cancelación fue aceptada. La factura ahora está cancelada.',
                'rejected' => 'Tu cliente rechazó la solicitud de cancelación. La factura sigue vigente.',
                'expired'  => 'El plazo para que tu cliente respondiera ha vencido sin resolución. Puedes intentar cancelar de nuevo.',
                'pending'  => 'La cancelación sigue pendiente de aprobación por parte de tu cliente.',
            ];

            $message = $messages[$result] ?? 'Estatus de cancelación actualizado.';

            if ($result === 'canceled') {
                // Release the linked POS sale so it can be invoiced again.
                if ($invoice->transaction_id) {
                    Transaction::where('id', $invoice->transaction_id)->update(['invoiced' => false]);
                }
            }

            if ($result === 'canceled' || $result === 'rejected') {
                return redirect()->route('billing.invoices.show', $invoice->id)
                    ->with($result === 'canceled' ? 'success' : 'warning', $message);
            }

            return redirect()->route('billing.invoices.show', $invoice->id)
                ->with('info', $message);

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Accept or reject a CFDI cancelation request (acting as the receiver RFC)
     * via SW Sapien. Returns a JSON payload so the modal can render the result
     * (acuse + folio status) inline without reloading the page.
     */
    public function acceptReject(AcceptRejectRequest $request, AcceptRejectInvoiceAction $action): JsonResponse
    {
        $profile = FiscalProfile::findOrFail($request->validated('fiscal_profile_id'));

        try {
            $result = $action->execute(
                $profile,
                $request->validated('uuid'),
                $request->validated('action'),
                (int) $request->user()->branch_id,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Local history of accept/reject responses for the current branch (JSON).
     */
    public function acceptRejectHistory(Request $request): JsonResponse
    {
        $history = AcceptRejectResponse::query()
            ->where('branch_id', $request->user()->branch_id)
            ->where('status', 'success')
            ->with('fiscalProfile:id,rfc,razon_social')
            ->orderBy('created_at', 'desc')
            ->limit((int) $request->input('limit', 30))
            ->get();

        return response()->json(['data' => $history]);
    }

    /**
     * Stamp a draft/pending invoice via SW Sapien (reservation flow).
     */
    public function stamp(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status->value, ['borrador', 'pendiente'])) {
            return redirect()->back()->with('error', 'Solo se pueden timbrar facturas en estado borrador o pendiente.');
        }

        // Un borrador con más de 72 horas ya no puede timbrarse con su fecha de
        // emisión original (regla del SAT). Si el usuario confirmó el cambio de
        // fecha, el CFDI se emite con la fecha y hora actuales.
        $effectiveDate = $invoice->issued_at ?? $invoice->created_at;

        if ($request->boolean('change_date') && $effectiveDate->diffInHours(now()) > 72) {
            $invoice->issued_at = now();
            $invoice->save();
        }

        try {
            $stampAction = app(\App\Actions\Billing\StampInvoiceAction::class);
            $stampAction->execute($invoice);
        } catch (\App\Exceptions\Billing\InsufficientStampsException $e) {
            return redirect()->back()->with('warning', $e->getMessage() . ' Tu factura sigue como prefactura; puedes timbrarla más tarde cuando tengas timbres disponibles.');
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('warning', $e->getMessage());
        }

        return redirect()->route('billing.invoices.show', $invoice->id)
            ->with('success', 'Factura timbrada correctamente.');
    }

    /**
     * Delete a draft invoice that hasn't been stamped yet.
     */
    public function destroy(Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status->value, ['borrador', 'pendiente'])) {
            return redirect()->back()->with('error', 'Solo se pueden eliminar facturas en estado borrador o pendiente.');
        }

        // Release the linked POS sale so it can be invoiced again.
        if ($invoice->transaction_id) {
            Transaction::where('id', $invoice->transaction_id)->update(['invoiced' => false]);
        }

        $invoice->items()->delete();
        $invoice->delete();

        return redirect()->route('billing.invoices.index')
            ->with('success', 'Prefactura eliminada correctamente.');
    }

}
