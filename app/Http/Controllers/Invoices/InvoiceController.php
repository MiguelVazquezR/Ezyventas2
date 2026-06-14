<?php

namespace App\Http\Controllers\Invoices;

use App\Actions\Invoices\CancelInvoiceAction;
use App\Actions\Invoices\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\CancelInvoiceRequest;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
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
            'invoices' => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters'  => $request->only(['search', 'status', 'sortField', 'sortOrder']),
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
            'customers'      => $user->branch->customers()->orderBy('name')->get(['id', 'name', 'company_name', 'tax_id', 'address']),
            'billingSetting' => $billingSetting,
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
