<?php

namespace App\Http\Controllers;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

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
    public function store(Request $request)
    {
        // Aquí irá la lógica para validar y guardar el producto.
        // Incluyendo la creación de los registros en `product_attributes` para las variantes.

        // dd($request->all()); // Para depurar lo que llega del formulario

        return redirect()->route('products.index')->with('success', 'Producto creado con éxito.');
    }
}
