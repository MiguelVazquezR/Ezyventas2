<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\IndexProductRequest;
use App\Http\Requests\Api\V1\Catalog\ShowProductRequest;
use App\Models\Product;
use App\Services\Catalog\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POS product catalog.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $products = $this->catalog
            ->queryForBranch($branchId, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json(
            $products->through(fn (Product $product) => $this->catalog->apiPayload($product, $branchId))
        );
    }

    public function show(ShowProductRequest $request, int $productId): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;
        $product = $this->catalog->findForBranch($productId, $branchId);

        if (!$product) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }

        return response()->json($this->catalog->apiPayload($product, $branchId));
    }
}
