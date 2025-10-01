<?php

namespace App\Http\Controllers;

use App\Enums\PromotionEffectType;
use App\Enums\PromotionRuleType;
use App\Enums\PromotionType;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductPromotionController extends Controller
{
    public function create(Product $product): Response
    {
        $user = Auth::user();
        $otherProducts = Product::where('branch_id', $user->branch->id)
            ->where('id', '!=', $product->id)
            ->get(['id', 'name']);

        return Inertia::render('Promotion/Create', [
            'product' => $product,
            'otherProducts' => $otherProducts,
        ]);
    }

    public function store(Request $request, Product $product)
    {
        // MODIFICADO: Actualización de reglas de validación para el paquete
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'type' => ['required', Rule::enum(PromotionType::class)],

            // Campos para Descuento (ITEM_DISCOUNT)
            'effect_type' => ['required_if:type,ITEM_DISCOUNT', 'nullable', Rule::enum(PromotionEffectType::class)],
            'effect_value' => 'required_if:type,ITEM_DISCOUNT|nullable|numeric|min:0',

            // Campos para BOGO
            'required_product_id' => 'required_if:type,BOGO|nullable|exists:products,id',
            'required_quantity' => 'required_if:type,BOGO|nullable|integer|min:1',
            'free_product_id' => 'required_if:type,BOGO|nullable|exists:products,id',
            'free_quantity' => 'required_if:type,BOGO|nullable|integer|min:1',

            // INICIA MODIFICACIÓN PARA PAQUETE
            'bundle_products' => 'required_if:type,BUNDLE_PRICE|nullable|array|min:1',
            'bundle_products.*.id' => 'required|exists:products,id',
            'bundle_products.*.quantity' => 'required|integer|min:1',
            'bundle_price' => 'required_if:type,BUNDLE_PRICE|nullable|numeric|min:0',
            // TERMINA MODIFICACIÓN
        ]);

        DB::transaction(function () use ($request, $product, $validated) {
            $subscriptionId = Auth::user()->branch->subscription_id;

            $promotion = Promotion::create([
                'subscription_id' => $subscriptionId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'],
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            switch ($validated['type']) {
                case PromotionType::ITEM_DISCOUNT->value:
                    $promotion->rules()->create(['type' => PromotionRuleType::REQUIRES_PRODUCT, 'value' => 1, 'itemable_id' => $product->id, 'itemable_type' => Product::class]);
                    $promotion->effects()->create(['type' => $validated['effect_type'], 'value' => $validated['effect_value'], 'itemable_id' => $product->id, 'itemable_type' => Product::class]);
                    break;
                case PromotionType::BOGO->value:
                    $promotion->rules()->create(['type' => PromotionRuleType::REQUIRES_PRODUCT_QUANTITY, 'itemable_id' => $validated['required_product_id'], 'itemable_type' => Product::class, 'value' => $validated['required_quantity']]);
                    $promotion->effects()->create(['type' => PromotionEffectType::FREE_ITEM, 'itemable_id' => $validated['free_product_id'], 'itemable_type' => Product::class, 'value' => $validated['free_quantity']]);
                    break;
                case PromotionType::BUNDLE_PRICE->value:
                    // MODIFICADO: Iterar sobre el array de objetos y usar la cantidad
                    foreach ($validated['bundle_products'] as $bundleItem) {
                        $promotion->rules()->create([
                            'type' => PromotionRuleType::REQUIRES_PRODUCT,
                            'itemable_id' => $bundleItem['id'],
                            'itemable_type' => Product::class,
                            'value' => $bundleItem['quantity'], // Usar la cantidad del formulario
                        ]);
                    }
                    $promotion->effects()->create(['type' => PromotionEffectType::SET_PRICE, 'value' => $validated['bundle_price']]);
                    break;
            }

            foreach ($promotion->getAffectedProducts() as $affectedProduct) {
                activity()
                    ->performedOn($affectedProduct)
                    ->causedBy(auth()->user())
                    ->event('promo')
                    ->withProperties(['promotion_name' => $promotion->name])
                    ->log("Se agregó la promoción '{$promotion->name}'.");
            }
        });

        return redirect()->route('products.show', $product->id)->with('success', 'Promoción creada con éxito.');
    }
}