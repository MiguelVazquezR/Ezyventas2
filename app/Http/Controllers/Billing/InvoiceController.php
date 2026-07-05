<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CancelInvoiceAction;
use App\Actions\Billing\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Models\Billing\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
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
            new Middleware('can:create invoices', only: ['create', 'store']),
            new Middleware('can:invoices.see_details', only: ['show']),
            new Middleware('can:invoices.edit', only: ['edit', 'update']),
            new Middleware('can:cancel invoices', only: ['cancel']),
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
            ? $subscription->fiscalProfiles()->orderBy('created_at', 'desc')->get()
            : collect();

        $totalInvoices = Invoice::where('branch_id', $user->branch_id)->count();
        $certifiedInvoices = Invoice::where('branch_id', $user->branch_id)->certified()->count();
        $canceledInvoices = Invoice::where('branch_id', $user->branch_id)->canceled()->count();

        $totalAmount = Invoice::where('branch_id', $user->branch_id)
            ->certified()
            ->sum('total');

        return Inertia::render('Billing/Dashboard/Index', [
            'fiscalProfiles'    => $fiscalProfiles,
            'totalStampsUsed'   => $certifiedInvoices,
            'totalInvoices'     => $totalInvoices,
            'canceledInvoices'  => $canceledInvoices,
            'totalAmount'       => (float) $totalAmount,
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
            ->with('customer:id,name,company_name');

        // Search across folio, receiver name, receiver RFC, and UUID
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('folio', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('receiver_legal_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('receiver_rfc', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('uuid', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $query->orderBy(
            $request->input('sortField', 'created_at'),
            $request->input('sortOrder', 'desc'),
        );

        return Inertia::render('Billing/Invoices/Index', [
            'invoices'           => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters'            => $request->only(['search', 'status', 'sortField', 'sortOrder']),
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

        return Inertia::render('Billing/Invoices/Create', [
            'customers'        => $user->branch->customers()->orderBy('name')->get(['id', 'name', 'company_name', 'tax_id', 'tax_regime', 'address']),
            'fiscalProfiles'   => $subscription?->fiscalProfiles()->active()->whereNotNull('sw_user_id')->get(['id', 'rfc', 'razon_social', 'regimen_fiscal', 'postal_code']) ?? [],
            'hasFiscalProfiles' => $subscription?->fiscalProfiles()->active()->whereNotNull('sw_user_id')->exists() ?? false,
        ]);
    }

    /**
     * Store a newly created CFDI invoice.
     */
    public function store(StoreInvoiceRequest $request, CreateInvoiceAction $action): RedirectResponse
    {
        $invoice = $action->execute(
            $request->validated(),
            Auth::user(),
        );

        return redirect()->route('billing.invoices.show', $invoice->id)
            ->with('success', 'Factura creada correctamente.');
    }

    /**
     * Display a single invoice with its items and customer.
     */
    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items', 'customer', 'branch', 'fiscalProfile']);

        return Inertia::render('Billing/Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Generate and stream a professional CFDI 4.0 PDF for the given invoice.
     *
     * Loads the invoice with all its relationships and passes the data
     * to a Blade template styled for the Mexican SAT CFDI 4.0 standard.
     * The PAC timbre data (UUID, SelloCFD, FechaTimbrado, etc.) is
     * extracted from the stored XML when available.
     */
    public function pdf(Invoice $invoice): \Illuminate\Http\Response
    {
        $invoice->load([
            'items',
            'customer',
            'branch.subscription.media',
            'fiscalProfile',
        ]);

        // ── Extract PAC timbre fiscal from stored XML ──
        $timbre = $this->extractTimbreData($invoice);

        // ── Format totals for the template ──
        $subtotal       = (float) $invoice->subtotal;
        $discountTotal  = (float) $invoice->discount_total;
        $taxesTotal     = (float) $invoice->taxes_total;
        $retainedTotal  = (float) $invoice->retained_taxes_total;
        $total          = (float) $invoice->total;

        // ── Retrieve company logo from Spatie MediaLibrary ──
        $logoBase64 = null;
        $subscription = $invoice->branch?->subscription;
        if ($subscription) {
            $logoMedia = $subscription->getFirstMedia('company_logo');
            if ($logoMedia && file_exists($logoMedia->getPath())) {
                $logoBase64 = base64_encode(file_get_contents($logoMedia->getPath()));
                $logoBase64 = 'data:' . $logoMedia->mime_type . ';base64,' . $logoBase64;
            }
        }

        $pdf = Pdf::loadView('billing.invoices.pdf', [
            'invoice'       => $invoice,
            'timbre'        => $timbre,
            'subtotal'      => $subtotal,
            'discountTotal' => $discountTotal,
            'taxesTotal'    => $taxesTotal,
            'retainedTotal' => $retainedTotal,
            'total'         => $total,
            'logoBase64'    => $logoBase64,
            // Group taxes and retentions for the global Impuestos summary
            'groupedTransfers'  => $this->groupTaxesByType($invoice),
            'groupedRetentions' => $this->groupRetentionsByType($invoice),
        ]);

        $pdf->setPaper('letter');

        return $pdf->stream('factura-' . ($invoice->series ? $invoice->series . '-' : '') . $invoice->folio . '.pdf');
    }

    /**
     * Extract SAT timbre fiscal data from the stored CFDI XML.
     *
     * Returns an associative array with UUID, FechaTimbrado, NoCertificadoSAT,
     * RfcProvCertif, SelloCFD, and SelloSAT — or empty defaults if the XML
     * is unavailable.
     */
    private function extractTimbreData(Invoice $invoice): array
    {
        $defaults = [
            'uuid'              => $invoice->uuid ?? '—',
            'fecha_timbrado'    => '—',
            'no_certificado_sat' => '—',
            'rfc_prov_certif'   => '—',
            'sello_cfd'         => '—',
            'sello_sat'         => '—',
        ];

        if (! $invoice->xml_url) {
            return $defaults;
        }

        $xmlContent = \Illuminate\Support\Facades\Storage::disk('public')->get($invoice->xml_url);

        if (! $xmlContent) {
            return $defaults;
        }

        // Suppress XML errors for potentially malformed CFDI
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();

        if (! $xml) {
            return $defaults;
        }

        // Register CFDI namespaces
        $namespaces = $xml->getNamespaces(true);

        // Timbre Fiscal Digital (tfd)
        $tfd = null;
        if (isset($namespaces['tfd'])) {
            $complemento = $xml->xpath('//cfdi:Complemento');
            if (! empty($complemento)) {
                $tfdNodes = $complemento[0]->children($namespaces['tfd'])->TimbreFiscalDigital ?? null;
                $tfd = $tfdNodes ? $tfdNodes[0] ?? $tfdNodes : null;
            }
        }

        return [
            'uuid'               => $invoice->uuid ?? (string) ($tfd->attributes()->UUID ?? '—'),
            'fecha_timbrado'     => $tfd ? (string) $tfd->attributes()->FechaTimbrado : '—',
            'no_certificado_sat' => $tfd ? (string) $tfd->attributes()->NoCertificadoSAT : '—',
            'rfc_prov_certif'    => $tfd ? (string) $tfd->attributes()->RfcProvCertif : '—',
            'sello_cfd'          => $tfd ? (string) $tfd->attributes()->SelloCFD : '—',
            'sello_sat'          => $tfd ? (string) $tfd->attributes()->SelloSAT : '—',
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
     */
    private function groupRetentionsByType(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            if (! $item->retained_tax_type || (float) $item->retained_tax_amount <= 0) {
                continue;
            }

            $key = $item->retained_tax_type;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'impuesto' => $item->retained_tax_type,
                    'importe'  => 0.0,
                ];
            }

            $groups[$key]['importe'] = round($groups[$key]['importe'] + (float) $item->retained_tax_amount, 2);
        }

        return array_values($groups);
    }

    /**
     * Display all fiscal profiles for the user's subscription.
     */
    public function settings(): Response
    {
        $user = Auth::user();
        $subscription = $user->branch?->subscription;

        $fiscalProfiles = $subscription
            ? $subscription->fiscalProfiles()->orderBy('created_at', 'desc')->get()
            : collect();

        return Inertia::render('Billing/Settings/Index', [
            'fiscalProfiles' => $fiscalProfiles,
        ]);
    }

    /**
     * Cancel a CFDI invoice (fiscal cancellation).
     */
    public function cancel(Invoice $invoice, CancelInvoiceRequest $request, CancelInvoiceAction $action): RedirectResponse
    {
        $action->execute(
            $invoice,
            $request->validated('cancellation_reason'),
            $request->validated('substitution_uuid'),
        );

        return redirect()->route('billing.invoices.show', $invoice->id)
            ->with('success', 'Factura cancelada correctamente.');
    }
}
