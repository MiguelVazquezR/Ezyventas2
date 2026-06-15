<?php

namespace App\Http\Controllers\Invoices;

use App\Actions\Invoices\CancelInvoiceAction;
use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\SaveBillingSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\CancelInvoiceRequest;
use App\Http\Requests\Invoices\SaveBillingSettingsRequest;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Models\BillingSetting;
use App\Models\Invoice;
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
            new Middleware('can:invoices.delete', only: ['destroy']),
            new Middleware('can:cancel invoices', only: ['cancel']),
            new Middleware('can:invoices.settings.access', only: ['settings', 'updateSettings']),
        ];
    }

    /**
     * List all invoices for the current branch.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

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

        return Inertia::render('Invoices/Index', [
            'invoices'           => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters'            => $request->only(['search', 'status', 'sortField', 'sortOrder']),
            'hasBillingSettings' => BillingSetting::where('branch_id', $user->branch_id)->exists(),
        ]);
    }

    /**
     * Show the invoice creation form.
     */
    public function create(): Response
    {
        $user = Auth::user();
        $billingSetting = $user->branch->billingSetting ?? null;

        return Inertia::render('Invoices/Create', [
            'customers'          => $user->branch->customers()->orderBy('name')->get(['id', 'name', 'company_name', 'tax_id', 'address']),
            'billingSetting'     => $billingSetting,
            'hasBillingSettings' => $billingSetting !== null,
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

        return redirect()->route('invoices.show', $invoice->id)
            ->with('success', 'Factura creada correctamente.');
    }

    /**
     * Display a single invoice with its items and customer.
     */
    public function show(Invoice $invoice): Response
    {
        $invoice->load(['items', 'customer', 'branch']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Show the fiscal billing settings form.
     */
    public function settings(): Response
    {
        $user = Auth::user();
        $billingSetting = BillingSetting::where('branch_id', $user->branch_id)->first();

        return Inertia::render('Invoices/Settings', [
            'billingSettings' => $billingSetting,
        ]);
    }

    /**
     * Save or update the fiscal billing settings for the current branch.
     */
    public function updateSettings(
        SaveBillingSettingsRequest $request,
        SaveBillingSettingsAction $action,
    ): RedirectResponse {
        $action->execute(
            $request->validated(),
            Auth::user()->branch_id,
        );

        return redirect()->route('invoices.settings')
            ->with('success', 'Configuración fiscal guardada correctamente.');
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

        return redirect()->route('invoices.show', $invoice->id)
            ->with('success', 'Factura cancelada correctamente.');
    }
}
