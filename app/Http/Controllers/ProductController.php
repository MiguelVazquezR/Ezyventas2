<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        // Inicia la consulta base de productos
        $query = Product::query();

        // Lógica para el filtro de tipo de producto (Mis productos vs Catálogo)
        if ($request->input('product_type', 'my_products') === 'my_products') {
            // Suponiendo que los productos del usuario no tienen 'global_product_id'
            // o pertenecen a su sucursal/usuario. Ajusta esta lógica según tu estructura.
            $query->whereNull('global_product_id');
        } else {
            // Productos del catálogo base
            $query->whereNotNull('global_product_id');
        }

        // Lógica para el buscador global
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('sku', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Aplica el ordenamiento
        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        // Obtiene los productos paginados
        $products = $query->paginate($request->input('rows', 20))
            ->withQueryString(); // Asegura que los parámetros de la URL se mantengan

        return Inertia::render('Product/Index', [
            'products' => $products,
            // Pasamos los filtros actuales a la vista para mantener el estado
            'filters' => $request->only(['search', 'product_type', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->subscription_id;
        $subscription = $user->subscription;

        // --- MARCAS ---
        $subscriberBrands = Brand::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $globalBrands = Brand::whereNull('subscription_id')
            ->whereHas('businessTypes', function ($query) use ($subscription) {
                $query->where('business_type_id', $subscription->business_type_id);
            })
            ->get(['id', 'name']);

        $formattedBrands = [
            ['label' => 'Mis Marcas', 'items' => $subscriberBrands],
            ['label' => 'Marcas del Catálogo', 'items' => $globalBrands],
        ];

        // --- DATOS ADICIONALES ---
        $categories = Category::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $providers = Provider::where('subscription_id', $subscriptionId)->get(['id', 'name']);
        $attributeDefinitions = AttributeDefinition::with('options')
            ->where('subscription_id', $subscriptionId)
            ->get();

        return Inertia::render('Product/Create', [
            'categories' => $categories,
            'brands' => $formattedBrands,
            'providers' => $providers,
            'attributeDefinitions' => $attributeDefinitions,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        DB::transaction(function () use ($request) {
            $user = Auth::user();
            $subscriptionId = $user->subscription_id;
            $validatedData = $request->validated();

            // Punto 1: Generar un slug único
            $baseSlug = Str::slug($validatedData['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Product::where('subscription_id', $subscriptionId)->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validatedData['slug'] = $slug;

            // Punto 6: Calcular el stock total si es un producto con variantes
            if ($validatedData['product_type'] === 'variant') {
                $variantsMatrix = json_decode($validatedData['variants_matrix'], true);
                $validatedData['current_stock'] = collect($variantsMatrix)
                    ->where('selected', true)
                    ->sum('current_stock');
            }

            // Punto 8 (Solución): Crear el producto excluyendo los campos de archivos
            $productData = collect($validatedData)->except(['general_images', 'variant_images', 'variants_matrix'])->all();

            $product = Product::create(
                array_merge(
                    $productData,
                    [
                        'subscription_id' => $subscriptionId,
                        'branch_id' => $user->subscription->branches()->first()->id,
                    ]
                )
            );

            // 2. Guardar imágenes generales
            if ($request->hasFile('general_images')) {
                foreach ($request->file('general_images') as $file) {
                    $product->addMedia($file)->toMediaCollection('product-general-images');
                }
            }

            // 3. Gestionar variantes y sus imágenes
            if ($validatedData['product_type'] === 'variant') {
                if ($request->hasFile('variant_images')) {
                    foreach ($request->file('variant_images') as $optionValue => $file) {
                        $product->addMedia($file)
                            ->withCustomProperties(['variant_option' => $optionValue])
                            ->toMediaCollection('product-variant-images');
                    }
                }

                $variantsMatrix = json_decode($validatedData['variants_matrix'], true);
                foreach ($variantsMatrix as $combination) {
                    if (empty($combination['selected'])) continue;

                    $attributes = collect($combination)->except([
                        'selected',
                        'sku_suffix',
                        'current_stock',
                        'min_stock',
                        'max_stock',
                        'selling_price',
                        'row_id'
                    ])->all();

                    $product->productAttributes()->create([
                        'attributes' => $attributes,
                        'sku_suffix' => $combination['sku_suffix'],
                        'current_stock' => $combination['current_stock'],
                        'min_stock' => $combination['min_stock'],
                        'max_stock' => $combination['max_stock'],
                        'selling_price_modifier' => $combination['selling_price'] - $product->selling_price,
                    ]);
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Producto creado con éxito.');
    }
}
