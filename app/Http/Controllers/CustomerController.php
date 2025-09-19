<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $query = Customer::query()
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            });

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('company_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('phone', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $customers = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Customer/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customer/Create');
    }

    public function store(StoreCustomerRequest $request)
    {
        Customer::create(array_merge($request->validated(), [
            'branch_id' => Auth::user()->branch_id,
        ]));

        return redirect()->route('customers.index')->with('success', 'Cliente creado con éxito.');
    }

    public function show(Customer $customer): Response
    {
        $customer->load(['transactions.user', 'balanceMovements']);

        return Inertia::render('Customer/Show', [
            'customer' => $customer,
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customer/Edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());
        return redirect()->route('customers.index')->with('success', 'Cliente actualizado con éxito.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Cliente eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Customer::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('customers.index')->with('success', 'Clientes seleccionados eliminados.');
    }
}

