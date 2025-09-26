<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;

class ServicesImport implements ToModel, WithHeadingRow
{
    private $categories;

    public function __construct()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        $this->categories = Category::where('subscription_id', $subscriptionId)
            ->where('type', 'service')
            ->pluck('id', 'name');
    }

    public function model(array $row)
    {
        $categoryId = $this->categories->get($row['categoria']);
        if (!$categoryId) {
            return null; // Omitir si la categoría no existe
        }

        return new Service([
            'name' => $row['nombre'],
            'slug' => Str::slug($row['nombre']),
            'description' => $row['descripcion'],
            'category_id' => $categoryId,
            'base_price' => $row['precio_base'],
            'duration_estimate' => $row['duracion_estimada'],
            'show_online' => strtolower($row['visible_en_tienda']) === 'si',
            'branch_id' => Auth::user()->branch_id,
        ]);
    }
}
