<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Customer;
use App\Models\Product;
use App\Enums\TransactionStatus;
use App\Enums\TransactionChannel;
use Carbon\Carbon;

Route::get('/migrate-transactions', function () {
    set_time_limit(0); 
    
    // --- RUTAS DE ARCHIVOS ---
    $ventasPath = public_path('stilos_database_tbl_clie_ventas.sql');
    $movimientosPath = public_path('stilos_database_tbl_mov_articulos.sql');
    $clientesPath = public_path('stilos_database_tbl_clientes.sql');
    
    // CONFIGURACIÓN
    $branchId = 7;
    $userId = 11;
    
    if (!file_exists($ventasPath) || !file_exists($movimientosPath)) {
        return response()->json(['error' => "Faltan archivos SQL en public."], 404);
    }

    Auth::loginUsingId($userId);

    $start = microtime(true);
    $stats = [
        'clients_mapped' => 0,
        'products_mapped' => 0,
        'transactions_created' => 0,
        'items_created' => 0,
        'errors' => 0
    ];

    // ==========================================
    // FASE 1: MAPEOS EN MEMORIA (Optimización)
    // ==========================================
    
    // 1.1 Mapa de Clientes (ID Antiguo -> Nombre) para buscarlos en DB
    $clientNameMap = []; // [LegacyID => Name]
    if (file_exists($clientesPath)) {
        $handle = fopen($clientesPath, "r");
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clientes`') !== false) {
                $rows = parseSqlValues2(extractValues($line));
                foreach ($rows as $row) {
                    // Index 0:ID, 2:Nombre
                    if (isset($row[0], $row[2])) {
                        $clientNameMap[cleanValue2($row[0])] = cleanValue2($row[2]);
                    }
                }
            }
        }
        fclose($handle);
    }
    $stats['clients_mapped'] = count($clientNameMap);

    // 1.2 Mapa de Productos (SKU -> ID Laravel)
    // Consultamos solo ID y SKU para no saturar memoria
    $productMap = Product::where('branch_id', $branchId)->pluck('id', 'sku')->toArray();
    $stats['products_mapped'] = count($productMap);

    // 1.3 Mapa de Transacciones Creadas (FolioLegacy -> ID Laravel)
    // Se llenará en la Fase 2
    $transactionIdMap = []; 


    // ==========================================
    // FASE 2: CREAR TRANSACCIONES (CABECERAS)
    // ==========================================
    $handleVentas = fopen($ventasPath, "r");
    DB::beginTransaction();
    
    try {
        $batchCount = 0;
        while (($line = fgets($handleVentas)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clie_ventas`') !== false) {
                $rows = parseSqlValues2(extractValues($line));
                
                foreach ($rows as $row) {
                    try {
                        // Indices: 0:Folio, 1:Tipo, 2:ClienteID, 3:Fecha, 8:Total, 12:Concepto
                        $folioLegacy = cleanValue2($row[0]);
                        $legacyClientId = cleanValue2($row[2]);
                        $date = parseDate2($row[3]);
                        $total = floatval($row[8]);
                        $concept = cleanValue2($row[12]);

                        // Resolver Cliente
                        $customerId = null;
                        if (isset($clientNameMap[$legacyClientId])) {
                            // Buscamos el ID real del cliente por nombre
                            // (Idealmente cachear esto también si son muchos)
                            $customerName = $clientNameMap[$legacyClientId];
                            $customerId = Customer::where('name', $customerName)
                                ->where('branch_id', $branchId)
                                ->value('id'); 
                        }

                        // Crear Transacción
                        $trx = Transaction::create([
                            'folio'             => 'LEG-' . $folioLegacy,
                            'customer_id'       => $customerId,
                            'branch_id'         => $branchId,
                            'user_id'           => $userId,
                            'status'            => TransactionStatus::COMPLETED,
                            'channel'           => TransactionChannel::MANUAL,
                            'subtotal'          => abs($total), // Se ajustará si los items varían, pero usamos el total guardado como verdad
                            'total_discount'    => 0, // Se calculará sumando items
                            'total_tax'         => 0,
                            'currency'          => 'MXN',
                            'notes'             => trim("Migración. Folio: $folioLegacy. $concept"),
                            'created_at'        => $date,
                            'updated_at'        => $date,
                        ]);

                        // Guardar en mapa para usar en Fase 3
                        $transactionIdMap[$folioLegacy] = $trx->id;
                        $stats['transactions_created']++;

                        $batchCount++;
                        if ($batchCount % 500 == 0) {
                            DB::commit();
                            DB::beginTransaction();
                        }
                    } catch (\Exception $e) {
                        Log::error("Error Venta $folioLegacy: " . $e->getMessage());
                        $stats['errors']++;
                    }
                }
            }
        }
        DB::commit(); // Commit final de ventas
        fclose($handleVentas);


        // ==========================================
        // FASE 3: INSERTAR ITEMS (DETALLES)
        // ==========================================
        
        // Abrimos transacción nueva para items
        DB::beginTransaction();
        $handleMovs = fopen($movimientosPath, "r");
        $batchCount = 0;

        while (($line = fgets($handleMovs)) !== false) {
            // Buscamos INSERTs en tbl_mov_articulos
            if (strpos($line, 'INSERT INTO `tbl_mov_articulos`') !== false) {
                $rows = parseSqlValues2(extractValues($line));

                foreach ($rows as $row) {
                    try {
                        // Índices basados en análisis del SQL:
                        // 1:FolioVenta, 2:SKU, 3:Desc, 5:Tipo('S'=Salida), 
                        // 8:PrecioUnit, 9:Cantidad, 11:DescPorcentaje, 12:TotalLinea
                        
                        $type = cleanValue2($row[5]);
                        $folioVenta = cleanValue2($row[1]);

                        // Solo procesamos SALIDAS ('S') que tengan un folio de venta conocido
                        if ($type === 'S' && isset($transactionIdMap[$folioVenta])) {
                            
                            $sku = cleanValue2($row[2]);
                            $description = cleanValue2($row[3]);
                            $unitPrice = floatval($row[8]);
                            $quantity = floatval($row[9]);
                            $discountPercent = floatval($row[11]);
                            
                            // Calcular montos
                            $grossAmount = $quantity * $unitPrice;
                            $discountAmount = $grossAmount * ($discountPercent / 100);
                            $lineTotal = $grossAmount - $discountAmount; // Debería coincidir con row[12]

                            // Buscar Producto Real
                            $productId = $productMap[$sku] ?? null;
                            $itemableType = $productId ? Product::class : null;

                            TransactionItem::create([
                                'transaction_id' => $transactionIdMap[$folioVenta],
                                'itemable_id'    => $productId,
                                'itemable_type'  => $itemableType,
                                'description'    => $description ?: 'Item Migrado',
                                'quantity'       => $quantity,
                                'unit_price'     => $unitPrice,
                                'discount_amount'=> $discountAmount,
                                'discount_reason'=> $discountPercent > 0 ? "Desc. $discountPercent%" : null,
                                'tax_amount'     => 0,
                                'line_total'     => $lineTotal,
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ]);

                            $stats['items_created']++;

                            $batchCount++;
                            if ($batchCount % 500 == 0) {
                                DB::commit();
                                DB::beginTransaction();
                            }
                        }
                    } catch (\Exception $e) {
                         // Errores en items no detienen la migración, solo se loguean
                        Log::warning("Error Item Folio $folioVenta: " . $e->getMessage());
                    }
                }
            }
        }
        
        DB::commit();
        fclose($handleMovs);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage(), 'line' => $e->getLine()], 500);
    }

    $executionTime = microtime(true) - $start;

    return response()->json([
        'status' => 'success',
        'message' => 'Migración completa de Ventas y Detalles.',
        'stats' => $stats,
        'time_seconds' => round($executionTime, 2)
    ]);
});

// --- FUNCIONES AUXILIARES ---

function extractValues($line) {
    $str = substr($line, strpos($line, 'VALUES') + 6);
    $str = trim($str);
    return (substr($str, -1) == ';') ? substr($str, 0, -1) : $str;
}

function parseSqlValues2($string) {
    $rows = [];
    $len = strlen($string);
    $currentVal = '';
    $inQuotes = false;
    $inParentheses = false;
    $row = [];
    
    for ($i = 0; $i < $len; $i++) {
        $char = $string[$i];
        if ($char == '(' && !$inQuotes && !$inParentheses) {
            $inParentheses = true; $row = []; $currentVal = ''; continue;
        }
        if ($char == ')' && !$inQuotes && $inParentheses) {
            $inParentheses = false; $row[] = $currentVal; $rows[] = $row; continue;
        }
        if ($char == "'" && ($i == 0 || $string[$i-1] != '\\')) {
            $inQuotes = !$inQuotes; continue;
        }
        if ($char == ',' && !$inQuotes && $inParentheses) {
            $row[] = $currentVal; $currentVal = ''; continue;
        }
        if ($inParentheses) $currentVal .= $char;
    }
    return $rows;
}

function cleanValue2($val) { return trim($val, "' "); }

function parseDate2($dateStr) {
    $d = cleanValue2($dateStr);
    return (empty($d) || $d === '0000-00-00') ? now() : Carbon::parse($d);
}