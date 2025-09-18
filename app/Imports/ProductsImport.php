<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToModel, WithHeadingRow
{
    private $categories;
    private $brands;

    public function __construct()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        // Cache para evitar consultas repetidas a la BD
        $this->categories = Category::where('subscription_id', $subscriptionId)->pluck('id', 'name');
        $this->brands = Brand::where('subscription_id', $subscriptionId)->pluck('id', 'name');
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        // Lógica para encontrar o crear categorías/marcas si es necesario
        $categoryId = $this->categories->get($row['categoria']);
        $brandId = $this->brands->get($row['marca']);

        return new Product([
            'name' => $row['nombre'],
            'sku' => $row['sku'],
            'description' => $row['descripcion'],
            'selling_price' => $row['precio_de_venta'],
            'cost_price' => $row['precio_de_compra'],
            'current_stock' => $row['stock_actual'],
            'min_stock' => $row['stock_minimo'],
            'max_stock' => $row['stock_maximo'],
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'branch_id' => Auth::user()->branch_id,
        ]);
    }
}