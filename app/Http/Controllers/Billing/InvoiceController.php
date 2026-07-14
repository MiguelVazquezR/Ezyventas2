<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CancelInvoiceAction;
use App\Actions\Billing\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Models\Billing\Invoice;
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
        try {
            $draft = $request->boolean('draft', false);
            $invoice = $action->execute(
                $request->validated(),
                Auth::user(),
                $draft,
            );

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

    /**
     * Stamp a draft/pending invoice via SW Sapien.
     */
    public function stamp(Invoice $invoice): RedirectResponse
    {
        if (! in_array($invoice->status->value, ['borrador', 'pendiente'])) {
            return redirect()->back()->with('error', 'Solo se pueden timbrar facturas en estado borrador o pendiente.');
        }

        $swService = app(\App\Services\Billing\SWSapienService::class);
        $swService->stamp($invoice);

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

        $invoice->items()->delete();
        $invoice->delete();

        return redirect()->route('billing.invoices.index')
            ->with('success', 'Prefactura eliminada correctamente.');
    }
}
