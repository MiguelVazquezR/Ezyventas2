<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\IndexServiceRequest;
use App\Models\Service;
use App\Services\Catalog\ServiceCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Services catalog of the branch, used to build service order items.
 */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(IndexServiceRequest $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $services = $this->catalog
            ->queryForBranch($branchId, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json(
            $services->through(fn (Service $service) => $this->catalog->payload($service))
        );
    }
}
