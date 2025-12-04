<?php

namespace App\Http\Controllers;

use App\Models\GlobalProduct;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Str;

class BaseCatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $search = $request->input('search');
        // 1. Ahora esperamos un array (o null)
        $categoryIds = $request->input('category_ids', []);

        // Esto es rápido porque solo cuenta IDs
        $totalImportedCount = Product::where('branch_id', $branchId)
            ->whereNotNull('global_product_id')
            ->count();

        $query = GlobalProduct::query()
            ->with(['category', 'brand', 'media'])
            ->when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%")->orWhere('sku', 'LIKE', "%{$search}%"))
            // 2. Filtro Multi-Categoría
            ->when(!empty($categoryIds), fn($q) => $q->whereIn('category_id', $categoryIds));

        // 3. Aumentamos la paginación a 24 o 30 ya que las tarjetas serán más pequeñas
        $globalProducts = $query->paginate(30)->withQueryString();

        $existingGlobalIds = Product::where('branch_id', $branchId)
            ->whereNotNull('global_product_id')
            ->whereIn('global_product_id', $globalProducts->pluck('id'))
            ->pluck('global_product_id')
            ->toArray();

        $globalProducts->getCollection()->transform(function ($gp) use ($existingGlobalIds) {
            return [
                'id' => $gp->id,
                'name' => $gp->name,
                'sku' => $gp->sku,
                'image_url' => $gp->getFirstMediaUrl('product-general-images') ?: null,
                'category' => $gp->category?->name ?? 'General',
                'brand' => $gp->brand?->name ?? '', // Quitamos "Genérico" para ahorrar espacio visual
                'suggested_price' => $gp->selling_price,
                'is_imported' => in_array($gp->id, $existingGlobalIds),
            ];
        });

        $categories = Category::whereNull('subscription_id')
            ->whereHas('globalProducts')
            ->select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Product/BaseCatalog', [
            'products' => $globalProducts,
            'categories' => $categories,
            // Devolvemos los filtros actuales para mantener el estado
            'filters' => [
                'search' => $search,
                'category_ids' => $categoryIds
            ],
            'totalImportedCount' => $totalImportedCount,
        ]);
    }

    /**
     * Maneja la importación masiva o individual
     */
    public function import(Request $request)
    {
        // Validamos que recibimos un array de IDs
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*' => 'exists:global_products,id',
        ]);

        $user = Auth::user();
        $branch = $user->branch;
        $importedCount = 0;

        DB::transaction(function () use ($validated, $branch, &$importedCount) {
            // Obtenemos los productos globales completos
            $globalProducts = GlobalProduct::with('media')
                ->whereIn('id', $validated['products'])
                ->get();

            foreach ($globalProducts as $globalProduct) {
                // 1. Evitar duplicados: Si ya existe este global_product_id en esta sucursal, saltar
                if (Product::where('branch_id', $branch->id)->where('global_product_id', $globalProduct->id)->exists()) {
                    continue;
                }

                // 2. Crear el Slug único
                $baseSlug = Str::slug($globalProduct->name);
                $slug = $baseSlug;
                $counter = 1;
                while (Product::where('branch_id', $branch->id)->where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }

                // 3. Crear el producto local
                $newProduct = Product::create([
                    'branch_id' => $branch->id,
                    'global_product_id' => $globalProduct->id,
                    'name' => $globalProduct->name,
                    'sku' => $globalProduct->sku,
                    'description' => $globalProduct->description,
                    'selling_price' => $globalProduct->selling_price,
                    // Importante: Asumimos que category_id y brand_id son globales o nulos.
                    // Si tus categorías son locales por sucursal, deberías dejar esto null o buscar la equivalente.
                    'category_id' => $globalProduct->category_id,
                    'brand_id' => $globalProduct->brand_id,
                    'slug' => $slug,
                    'current_stock' => 0, // Stock inicial en 0
                    'show_online' => false,
                ]);

                // 4. Copiar Imágenes (Usando Spatie Media Library)
                // Copiamos de la colección 'global-product-images' a 'product-general-images'
                $mediaItems = $globalProduct->getMedia('global-product-images');
                foreach ($mediaItems as $media) {
                    try {
                        $media->copy($newProduct, 'product-general-images');
                    } catch (\Exception $e) {
                        // Silenciamos error de imagen para no detener la transacción si falla una foto
                        // Log::error("Error copiando imagen producto {$globalProduct->id}: " . $e->getMessage());
                    }
                }

                $importedCount++;
            }
        });

        if ($importedCount === 0) {
            return back()->with('warning', 'Los productos seleccionados ya estaban en tu catálogo.');
        }

        return back()->with('success', "Se han importado {$importedCount} productos correctamente.");
    }
}
