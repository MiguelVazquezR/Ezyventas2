<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Models\Category;
use Carbon\Carbon;

Route::get('/migrate-products', function () {
    // --- CONFIGURACIÓN ---
    set_time_limit(0); // Permitir ejecución larga
    $filePath = public_path('stilos_database_tbl_articulos.sql');
    
    // IDs del Sistema Destino
    $subscriptionId = 6; 
    $branchId = 7;
    $userId = 11;

    if (!file_exists($filePath)) {
        return response()->json(['error' => "Archivo no encontrado en: $filePath"], 404);
    }

    // 1. Autenticar al usuario para que los logs (si los hay) queden a su nombre
    Auth::loginUsingId($userId);

    // 2. Pre-cargar o crear las categorías necesarias
    // Mapeo: 'CodigoSQL' => 'NombreCategoriaLaravel'
    $catMappingNames = [
        '001' => 'Ropa',
        '002' => 'Zapatos',
        '003' => 'Accesorios'
    ];

    $categoryIds = [];

    // Crear categorías específicas
    foreach ($catMappingNames as $code => $name) {
        $cat = Category::firstOrCreate(
            ['name' => $name, 'subscription_id' => $subscriptionId],
            ['type' => 'product'] 
        );
        $categoryIds[$code] = $cat->id;
    }

    // Categoría por defecto para lo que no coincida
    $defaultCategory = Category::firstOrCreate(
        ['name' => 'Migración', 'subscription_id' => $subscriptionId],
        ['type' => 'product']
    );

    // --- PROCESAMIENTO ---
    $handle = fopen($filePath, "r");
    $count = 0;
    $errors = 0;

    // Iniciamos transacción
    DB::beginTransaction();

    try {
        while (($line = fgets($handle)) !== false) {
            // Buscamos líneas con INSERT INTO para la tabla de artículos
            if (strpos($line, 'INSERT INTO `tbl_articulos`') !== false) {
                
                // Limpiar la cadena para dejar solo los valores
                $valuesPart = substr($line, strpos($line, 'VALUES') + 6);
                $valuesPart = trim($valuesPart);
                if (substr($valuesPart, -1) == ';') {
                    $valuesPart = substr($valuesPart, 0, -1);
                }

                // Parsear los valores (función auxiliar abajo)
                $rows = parseSqlValues3($valuesPart);

                foreach ($rows as $row) {
                    try {
                        // --- MAPEO DE DATOS ---
                        // Indices SQL: 
                        // 0:Codigo(SKU), 1:Descripcion, 2:Unidad(Categoria), 11:Costo, 12:Precio
                        // 18:Exi_Ini, 19:Ent_Mes, 20:Sal_Mes (Stock = 18+19-20)
                        
                        $sku = cleanValue3($row[0]);
                        $name = cleanValue3($row[1]);
                        $unitCode = cleanValue3($row[2]); // Usamos 'Unidad' para definir Categoría
                        
                        // Determinar ID de Categoría
                        $catId = $categoryIds[$unitCode] ?? $defaultCategory->id;

                        // Cálculo de Stock
                        $stock = floatval($row[18]) + floatval($row[19]) - floatval($row[20]);
                        
                        // Fechas
                        $createdAt = parseDate3($row[5]);
                        $updatedAt = parseDate3($row[6]);

                        // Crear/Actualizar Producto
                        Product::updateOrCreate(
                            [
                                'sku'       => $sku, 
                                'branch_id' => $branchId
                            ],
                            [
                                'name'              => $name,
                                'description'       => $name, // Usamos nombre como descripción
                                'measure_unit'      => 'pza', // Unidad de medida por defecto
                                'cost_price'        => floatval($row[11]),
                                'selling_price'     => floatval($row[12]),
                                'online_price'      => floatval($row[12]),
                                'current_stock'     => max(0, $stock),
                                'min_stock'         => 0,
                                'category_id'       => $catId, // ID Categoría correcta
                                'branch_id'         => $branchId,
                                'product_type'      => 'simple',
                                'currency'          => 'MXN',
                                'show_online'       => true,
                                'requires_shipping' => false,
                                'created_at'        => $createdAt,
                                'updated_at'        => $updatedAt
                            ]
                        );

                        $count++;

                        // Commit parcial cada 500 registros para liberar memoria
                        if ($count % 500 == 0) {
                            DB::commit();
                            DB::beginTransaction();
                        }

                    } catch (\Exception $e) {
                        Log::error("Error migrando SKU " . ($row[0] ?? '?') . ": " . $e->getMessage());
                        $errors++;
                    }
                }
            }
        }

        // Commit final
        DB::commit();
        fclose($handle);

        return response()->json([
            'status' => 'success',
            'message' => 'Migración finalizada correctamente.',
            'total_processed' => $count,
            'errors' => $errors,
            'categories_created' => array_merge($catMappingNames, ['default' => 'Migración'])
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        if (is_resource($handle)) fclose($handle);
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'line' => $e->getLine()
        ], 500);
    }
});

// --- FUNCIONES AUXILIARES ---

function parseSqlValues3($string) {
    $rows = [];
    $len = strlen($string);
    $currentVal = '';
    $inQuotes = false;
    $inParentheses = false;
    $row = [];
    
    for ($i = 0; $i < $len; $i++) {
        $char = $string[$i];
        
        // Inicio de fila (
        if ($char == '(' && !$inQuotes && !$inParentheses) {
            $inParentheses = true;
            $row = [];
            $currentVal = '';
            continue;
        }
        
        // Fin de fila )
        if ($char == ')' && !$inQuotes && $inParentheses) {
            $inParentheses = false;
            $row[] = $currentVal; // Guardar último valor
            $rows[] = $row;       // Guardar fila completa
            continue;
        }
        
        // Manejo de comillas simples '
        if ($char == "'" && ($i == 0 || $string[$i-1] != '\\')) {
            $inQuotes = !$inQuotes;
            // Opcional: no agregar la comilla al valor para limpiarlo de una vez
            continue; 
        }
        
        // Separador de valores ,
        if ($char == ',' && !$inQuotes && $inParentheses) {
            $row[] = $currentVal;
            $currentVal = '';
            continue;
        }
        
        // Acumular caracteres del valor
        if ($inParentheses) {
            $currentVal .= $char;
        }
    }
    return $rows;
}

function cleanValue3($val) {
    return trim($val, "' ");
}

function parseDate3($dateStr) {
    $dateStr = cleanValue3($dateStr);
    if (empty($dateStr) || $dateStr === '0000-00-00') {
        return now();
    }
    try {
        return Carbon::parse($dateStr);
    } catch (\Exception $e) {
        return now();
    }
}