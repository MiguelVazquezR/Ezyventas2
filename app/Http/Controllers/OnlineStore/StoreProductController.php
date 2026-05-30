<?php

namespace App\Http\Controllers\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\UpdateStoreProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class StoreProductController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $products = Product::with(['category', 'media'])
            ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
            ->orderBy('store_sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('OnlineStore/Products/Index', [
            'products' => $products,
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorizeProduct($product);

        $product->load('media', 'category');

        return Inertia::render('OnlineStore/Products/Form', [
            'product' => $product,
        ]);
    }

    public function update(UpdateStoreProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorizeProduct($product);

        $product->update($request->validated());

        if ($request->hasFile('image')) {
            $product->clearMediaCollection('product-general-images');
            $product->addMediaFromRequest('image')->toMediaCollection('product-general-images');
        }

        return redirect()->route('online-store.products.index')
            ->with('success', 'Product updated successfully.');
    }

    public function toggle(Product $product): RedirectResponse
    {
        $this->authorizeProduct($product);

        $product->update(['show_online' => !$product->show_online]);

        return back()->with('success', $product->show_online
            ? 'Product is now visible in your store.'
            : 'Product is now hidden from your store.');
    }

    public function toggleFeatured(Product $product): RedirectResponse
    {
        $this->authorizeProduct($product);

        $product->update(['is_featured' => !$product->is_featured]);

        return back()->with('success', $product->is_featured
            ? 'Product is now featured in your store.'
            : 'Product is no longer featured.');
    }

    public function updateSortOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:products,id'],
            'items.*.store_sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        foreach ($validated['items'] as $item) {
            Product::where('id', $item['id'])
                ->whereHas('branch', fn($q) => $q->where('subscription_id', $subscriptionId))
                ->update(['store_sort_order' => $item['store_sort_order']]);
        }

        return back()->with('success', 'Product order updated.');
    }

    private function authorizeProduct(Product $product): void
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        if ($product->branch?->subscription_id !== $subscriptionId) {
            abort(403);
        }
    }
}
