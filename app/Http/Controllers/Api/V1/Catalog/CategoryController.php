<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\IndexCategoryRequest;
use App\Services\Catalog\CategoryCatalogService;
use Illuminate\Http\JsonResponse;

/**
 * Product and service categories of the subscription.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly CategoryCatalogService $categories) {}

    public function index(IndexCategoryRequest $request): JsonResponse
    {
        $subscriptionId = (int) $request->user()->branch->subscription_id;

        return response()->json($this->categories->forSubscription($subscriptionId, $request->type()));
    }
}
