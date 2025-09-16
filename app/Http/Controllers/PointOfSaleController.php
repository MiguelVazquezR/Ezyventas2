<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PointOfSaleController extends Controller
{
    /**
     * Muestra la vista principal del Punto de Venta.
     */
    public function index(): Response
    {
        // Corregí el nombre del componente para que coincida con tu archivo
        return Inertia::render('POS/Index', [
            'products' => $this->getProductsData(),
            'categories' => $this->getCategoriesData(),
            'pendingCarts' => $this->getPendingCartsData(),
            'initialClient' => $this->getInitialClientData(),
        ]);
    }

    // --- MÉTODOS PRIVADOS CON DATOS DE EJEMPLO ---

    private function getProductsData(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Vestido de mezclilla con cinturón',
                'category' => 'Ropa de mujer',
                'price' => 290.00,
                'stock' => 8,
                'image' => 'https://placehold.co/400x400/A7D2F5/333?text=Vestido',
                'code' => 'WD-001',
                'provider' => 'Fashion Co.',
                'description' => '<ul><li>Tela de mezclilla ligera.</li><li>Incluye cinturón ajustable.</li><li>Perfecto para salidas casuales.</li></ul>',
                'variants' => [
                    'Talla' => [
                        ['value' => 'S', 'stock' => 2],
                        ['value' => 'M', 'stock' => 5],
                        ['value' => 'L', 'stock' => 1],
                    ],
                    'Color' => [
                        ['value' => 'Azul Claro', 'stock' => 4],
                        ['value' => 'Azul Oscuro', 'stock' => 4],
                    ]
                ]
            ],
            [
                'id' => 2,
                'name' => 'Suéter invierno de lana',
                'category' => 'Ropa de hombre',
                'price' => 290.00,
                'stock' => 8,
                'image' => 'https://placehold.co/400x400/D2B48C/333?text=Sueter',
                'code' => 'MS-002',
                'provider' => 'Warm Knits',
                'description' => '<ul><li>100% lana de merino.</li><li>Corte clásico y cómodo.</li><li>Ideal para climas fríos.</li></ul>',
                'variants' => [
                    'Talla' => [
                        ['value' => 'L', 'stock' => 3],
                        ['value' => 'XL', 'stock' => 5],
                    ],
                ]
            ],
            [
                'id' => 4,
                'name' => 'Tenis deportivos MIKA',
                'category' => 'Calzado de mujer',
                'price' => 290.00,
                'stock' => 5,
                'image' => 'https://placehold.co/400x400/FADBD8/333?text=Tenis',
                'code' => 'SH-004',
                'provider' => 'Sporty Shoes',
                'description' => '<ul><li>Tenis deportivo para mujer.</li><li>Corte sintético.</li><li>Tela transpirable.</li></ul>',
                'variants' => [
                    'Talla' => [
                        ['value' => '23.5', 'stock' => 1],
                        ['value' => '24', 'stock' => 2],
                        ['value' => '24.5', 'stock' => 2],
                        ['value' => '26', 'stock' => 0],
                    ],
                     'Color' => [
                        ['value' => 'Rosa', 'stock' => 3],
                        ['value' => 'Blanco', 'stock' => 2],
                    ]
                ]
            ],
        ];
    }

    private function getCategoriesData(): array
    {
         return [
            ['id' => 0, 'name' => 'Todos los productos', 'count' => 25],
            ['id' => 1, 'name' => 'Productos sin código', 'count' => 7],
            ['id' => 2, 'name' => 'Ropa de mujer', 'count' => 5],
            ['id' => 3, 'name' => 'Ropa de hombre', 'count' => 2],
            ['id' => 4, 'name' => 'Calzado', 'count' => 3],
            ['id' => 5, 'name' => 'Papelería', 'count' => 8],
        ];
    }

    private function getPendingCartsData(): array
    {
        return [
            ['id' => 1, 'client' => 'Sofía Hernández Corona', 'seller' => 'Cristina Flores P...', 'total' => 405.00, 'time' => 'Hace 15 min'],
            ['id' => 2, 'client' => 'Alejandra Cisneros Ríos', 'seller' => 'Cristina Flores P...', 'total' => 1565.00, 'time' => '03 ago 2025, 03:40 pm'],
        ];
    }
    
    private function getInitialClientData(): array
    {
        return [
            'name' => 'Rosario Benites Flores',
            'phone' => '33 48 27 36 69',
        ];
    }
}