<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Actions\Customers\CreateCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\IndexCustomerRequest;
use App\Http\Requests\Api\V1\Customers\ShowCustomerRequest;
use App\Http\Requests\Api\V1\Customers\StoreCustomerRequest;
use App\Models\Customer;
use App\Services\Customers\CustomerReadService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Customers of the branch: search, profile and quick creation from the POS.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerReadService $customers) {}

    public function index(IndexCustomerRequest $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $customers = $this->customers
            ->queryForBranch($branchId, $request->search())
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json(
            $customers->through(fn (Customer $customer) => $this->customers->payload($customer))
        );
    }

    public function show(ShowCustomerRequest $request, int $customerId): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;
        $customer = $this->customers->findForBranch($customerId, $branchId);

        if (!$customer) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return response()->json($this->customers->detailPayload($customer));
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $customer = $action->execute($request->validated(), $request->user());

        return response()->json($this->customers->payload($customer), 201);
    }
}
