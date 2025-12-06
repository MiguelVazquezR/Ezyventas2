<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Payment;
use App\Enums\TransactionStatus;
use App\Enums\TransactionChannel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Carbon\Carbon;

Route::get('/migrate-transactions', function (Request $request) {
    set_time_limit(0);
    
    // 1. CONFIGURACIÓN DINÁMICA
    // Ejemplo de uso: /migrate-transactions?year=2024
    $filterYear = $request->input('year', date('Y')); 
    
    $branchId = 7;
    $userId = 11;
    
    // Rutas de archivos
    $ventasPath = public_path('stilos_database_tbl_clie_ventas.sql');
    $movimientosPath = public_path('stilos_database_tbl_mov_articulos.sql');
    $pagosPath = public_path('stilos_database_tbl_clie_pagos.sql');
    $clientesPath = public_path('stilos_database_tbl_clientes.sql');

    if (!file_exists($ventasPath) || !file_exists($movimientosPath) || !file_exists($pagosPath)) {
        return response()->json(['error' => "Faltan archivos SQL en public."], 404);
    }

    Auth::loginUsingId($userId);
    $start = microtime(true);

    $stats = [
        'year' => $filterYear,
        'transactions' => 0,
        'items' => 0,
        'payments' => 0,
        'skipped_public_customer' => 0
    ];

    // ==========================================
    // FASE 0: PREPARAR CONSECUTIVO DE FOLIOS
    // ==========================================
    $lastTransaction = Transaction::where('branch_id', $branchId)
        ->where('folio', 'like', 'V-%')
        ->orderBy('id', 'desc')
        ->first();

    $currentSequence = 0;
    if ($lastTransaction) {
        $parts = explode('-', $lastTransaction->folio);
        if (isset($parts[1])) $currentSequence = (int) $parts[1];
    }

    // ==========================================
    // FASE 1: MAPEOS EN MEMORIA (Ultra Rápido)
    // ==========================================
    
    // 1.1 Mapa ID Legacy -> Nombre (Desde el SQL viejo)
    $legacyIdToName = [];
    if (file_exists($clientesPath)) {
        $handle = fopen($clientesPath, "r");
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clientes`') !== false) {
                $rows = parseSqlValues(extractValues($line));
                foreach ($rows as $row) {
                    if (isset($row[0], $row[2])) {
                        $legacyIdToName[cleanValue($row[0])] = cleanValue($row[2]);
                    }
                }
            }
        }
        fclose($handle);
    }

    // 1.2 Mapa Nombre -> ID Laravel (Desde tu BD actual)
    // Cargamos TODOS los clientes de la sucursal en un array ['JUAN PEREZ' => 105, ...]
    // Esto evita hacer una query por cada venta.
    $dbCustomers = Customer::where('branch_id', $branchId)->pluck('id', 'name')->toArray();

    // 1.3 Mapa de Productos
    $productMap = Product::where('branch_id', $branchId)->pluck('id', 'sku')->toArray();
    
    // 1.4 Mapa Transacciones (LegacyFolio -> NewTransactionID)
    $transactionIdMap = []; 

    DB::beginTransaction();

    try {
        // ==========================================
        // FASE 2: TRANSACCIONES (CABECERAS)
        // ==========================================
        $handleVentas = fopen($ventasPath, "r");
        $batchCount = 0;
        
        while (($line = fgets($handleVentas)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clie_ventas`') !== false) {
                $rows = parseSqlValues(extractValues($line));
                
                foreach ($rows as $row) {
                    $date = parseDate($row[3]);

                    // --- FILTRO: SOLO AÑO SELECCIONADO ---
                    if ($date->year != $filterYear) continue;

                    $currentSequence++;
                    $newFolio = 'V-' . str_pad($currentSequence, 3, '0', STR_PAD_LEFT);
                    
                    $legacyFolio = cleanValue($row[0]);
                    $legacyClientId = cleanValue($row[2]);
                    $total = floatval($row[8]);
                    $concept = cleanValue($row[12]);

                    // --- RESOLVER CLIENTE ---
                    $customerId = null;
                    
                    // 1. Obtener nombre del sistema viejo
                    $clientName = $legacyIdToName[$legacyClientId] ?? null;
                    
                    // 2. Verificar si es "CLIENTE DE MOSTRADOR"
                    if ($clientName && strtoupper(trim($clientName)) === 'CLIENTE DE MOSTRADOR') {
                        $customerId = null; // Público en general
                        $stats['skipped_public_customer']++;
                    } elseif ($clientName) {
                        // 3. Buscar ID en el mapa de clientes reales
                        $customerId = $dbCustomers[$clientName] ?? null;
                    }

                    // Crear Transacción
                    $trx = Transaction::create([
                        'folio'             => $newFolio,
                        'customer_id'       => $customerId,
                        'branch_id'         => $branchId,
                        'user_id'           => $userId,
                        'status'            => TransactionStatus::COMPLETED,
                        'channel'           => TransactionChannel::POS,
                        'subtotal'          => abs($total), 
                        'total_discount'    => 0,
                        'total_tax'         => 0,
                        'currency'          => 'MXN',
                        // 'notes'             => trim("Migración $filterYear (Ref: $legacyFolio). $concept"),
                        'created_at'        => $date,
                        'updated_at'        => $date,
                    ]);

                    $transactionIdMap[$legacyFolio] = $trx->id;
                    $stats['transactions']++;
                    
                    if (++$batchCount % 200 == 0) { DB::commit(); DB::beginTransaction(); }
                }
            }
        }
        fclose($handleVentas);

        // ==========================================
        // FASE 3: ITEMS (DETALLES)
        // ==========================================
        $handleMovs = fopen($movimientosPath, "r");
        while (($line = fgets($handleMovs)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_mov_articulos`') !== false) {
                $rows = parseSqlValues(extractValues($line));
                foreach ($rows as $row) {
                    $legacyFolioRef = cleanValue($row[1]);
                    $type = cleanValue($row[5]); // 'S' = Salida

                    if ($type === 'S' && isset($transactionIdMap[$legacyFolioRef])) {
                        $sku = cleanValue($row[2]);
                        $description = cleanValue($row[3]);
                        $unitPrice = floatval($row[8]);
                        $quantity = floatval($row[9]);
                        $discountPercent = floatval($row[11]);
                        
                        $grossAmount = $quantity * $unitPrice;
                        $discountAmount = $grossAmount * ($discountPercent / 100);
                        $lineTotal = $grossAmount - $discountAmount;

                        TransactionItem::create([
                            'transaction_id' => $transactionIdMap[$legacyFolioRef],
                            'itemable_id'    => $productMap[$sku] ?? null,
                            'itemable_type'  => isset($productMap[$sku]) ? Product::class : null,
                            'description'    => $description ?: 'Producto Migrado',
                            'quantity'       => $quantity,
                            'unit_price'     => $unitPrice,
                            'discount_amount'=> $discountAmount,
                            'discount_reason'=> $discountPercent > 0 ? "$discountPercent%" : null,
                            'tax_amount'     => 0,
                            'line_total'     => $lineTotal,
                            'created_at'     => now(), // Usamos now porque la fecha real está en la transacción padre
                            'updated_at'     => now(),
                        ]);
                        $stats['items']++;
                        if (++$batchCount % 200 == 0) { DB::commit(); DB::beginTransaction(); }
                    }
                }
            }
        }
        fclose($handleMovs);

        // ==========================================
        // FASE 4: PAGOS (NUEVO)
        // ==========================================
        $handlePagos = fopen($pagosPath, "r");
        while (($line = fgets($handlePagos)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clie_pagos`') !== false) {
                $rows = parseSqlValues(extractValues($line));
                foreach ($rows as $row) {
                    // SQL Legacy: 1:IdVenta(FolioLegacy), 3:Fecha, 4:Metodo, 5:Monto, 6:Notas
                    $legacyVentaId = cleanValue($row[1]);
                    
                    // Solo insertar pagos si la venta existe (es del año filtrado)
                    if (isset($transactionIdMap[$legacyVentaId])) {
                        
                        $paymentDate = parseDate($row[3]);
                        $methodStr = mb_strtolower(cleanValue($row[4]));
                        $amount = floatval($row[5]);
                        $notes = cleanValue($row[6]);

                        // Mapeo Inteligente de Método
                        $method = PaymentMethod::CASH;
                        if (str_contains($methodStr, 'tarjeta') || str_contains($methodStr, 'debito') || str_contains($methodStr, 'credito')) {
                            $method = PaymentMethod::CARD;
                        } elseif (str_contains($methodStr, 'transferencia') || str_contains($methodStr, 'cheque') || str_contains($methodStr, 'deposito')) {
                            $method = PaymentMethod::TRANSFER;
                        }
                        
                        Payment::create([
                            'transaction_id' => $transactionIdMap[$legacyVentaId],
                            'cash_register_session_id' => null, // Histórico no tiene sesión
                            'amount' => $amount,
                            'payment_method' => $method,
                            'status' => PaymentStatus::COMPLETED,
                            'payment_date' => $paymentDate,
                            'notes' => $notes ?: 'Pago Migrado',
                            'created_at' => $paymentDate,
                            'updated_at' => $paymentDate,
                        ]);

                        $stats['payments']++;
                        if (++$batchCount % 200 == 0) { DB::commit(); DB::beginTransaction(); }
                    }
                }
            }
        }
        fclose($handlePagos);

        DB::commit();

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()], 500);
    }

    $time = microtime(true) - $start;

    return response()->json([
        'status' => 'success',
        'message' => "Migración del año $filterYear finalizada con éxito.",
        'stats' => $stats,
        'time_seconds' => round($time, 2)
    ]);
});

// --- HELPERS ---
function extractValues($line) {
    $str = substr($line, strpos($line, 'VALUES') + 6);
    $str = trim($str);
    return (substr($str, -1) == ';') ? substr($str, 0, -1) : $str;
}

function parseSqlValues($string) {
    $rows = []; $len = strlen($string); $currentVal = ''; $inQuotes = false; $inParentheses = false; $row = [];
    for ($i = 0; $i < $len; $i++) {
        $char = $string[$i];
        if ($char == '(' && !$inQuotes && !$inParentheses) { $inParentheses = true; $row = []; $currentVal = ''; continue; }
        if ($char == ')' && !$inQuotes && $inParentheses) { $inParentheses = false; $row[] = $currentVal; $rows[] = $row; continue; }
        if ($char == "'" && ($i == 0 || $string[$i-1] != '\\')) { $inQuotes = !$inQuotes; continue; }
        if ($char == ',' && !$inQuotes && $inParentheses) { $row[] = $currentVal; $currentVal = ''; continue; }
        if ($inParentheses) $currentVal .= $char;
    }
    return $rows;
}
function cleanValue($val) { return trim($val, "' "); }
function parseDate($dateStr) {
    $d = cleanValue($dateStr);
    return (empty($d) || $d === '0000-00-00') ? now() : Carbon::parse($d);
}