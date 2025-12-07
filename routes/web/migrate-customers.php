<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Customer;
use Carbon\Carbon;

Route::get('/migrate-customers', function () {
    // --- CONFIGURACIÓN ---
    set_time_limit(0); 
    $filePath = public_path('stilos_database_tbl_clientes.sql');
    
    // IDs del Sistema Destino
    $branchId = 7;
    $userId = 11;

    if (!file_exists($filePath)) {
        return response()->json(['error' => "Archivo no encontrado en: $filePath"], 404);
    }

    Auth::loginUsingId($userId);

    $handle = fopen($filePath, "r");
    $count = 0;
    $errors = 0;

    DB::beginTransaction();

    try {
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, 'INSERT INTO `tbl_clientes`') !== false) {
                
                $valuesPart = substr($line, strpos($line, 'VALUES') + 6);
                $valuesPart = trim($valuesPart);
                if (substr($valuesPart, -1) == ';') {
                    $valuesPart = substr($valuesPart, 0, -1);
                }

                $rows = parseSqlValuesCustomers($valuesPart);

                foreach ($rows as $row) {
                    try {
                        // Índices SQL: 
                        // 0:ID, 1:Rfc, 2:Nombre, 3:Direccion, 4:Colonia, 5:CoPo, 
                        // 6:Ciudad, 7:Estado, 10:Tel1, 11:Tel2, 12:Email, 13:FechaInicio, 
                        // 15:LimiteCredito, 20:SaldoInicial, 21:Cargos, 22:Abonos
                        
                        $legacyId = cleanValue2($row[0]);
                        $name = cleanValue2($row[2]);
                        $rfc = cleanValue2($row[1]);
                        
                        $phone = cleanValue2($row[10]);
                        if (empty($phone)) $phone = cleanValue2($row[11]);

                        $email = cleanValue2($row[12]);
                        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $email = null;
                        }

                        $addressData = [
                            'street'   => cleanValue2($row[3]),
                            'colony'   => cleanValue2($row[4]),
                            'zip_code' => cleanValue2($row[5]),
                            'city'     => cleanValue2($row[6]),
                            'state'    => cleanValue2($row[7]),
                            'country'  => 'MX' 
                        ];

                        // --- CORRECCIÓN DE SALDO ---
                        // Sistema Nuevo: Negativo es Deuda.
                        // Fórmula: Abonos - (SaldoInicial + Cargos)
                        // Ejemplo: Debe 100 (Cargo) y pagó 0. Resultado: 0 - 100 = -100 (Deuda correcta)
                        $balance = floatval($row[22]) - (floatval($row[20]) + floatval($row[21]));
                        
                        $createdAt = parseDate2($row[13]);
                        
                        Customer::updateOrCreate(
                            [
                                'name'      => $name,
                                'branch_id' => $branchId
                            ],
                            [
                                'company_name' => null,
                                'tax_id'       => $rfc,
                                'phone'        => $phone,
                                'email'        => $email,
                                'address'      => $addressData, 
                                'credit_limit' => floatval($row[15]),
                                'balance'      => $balance, // Saldo corregido
                                'created_at'   => $createdAt,
                                'updated_at'   => now()
                            ]
                        );

                        $count++;

                        if ($count % 200 == 0) {
                            DB::commit();
                            DB::beginTransaction();
                        }

                    } catch (\Exception $e) {
                        // Manejo de duplicados de email intentando insertar sin él
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'email') !== false) {
                             try {
                                Customer::updateOrCreate(
                                    ['name' => $name, 'branch_id' => $branchId],
                                    [
                                        'tax_id'       => $rfc,
                                        'phone'        => $phone,
                                        'email'        => null, 
                                        'address'      => $addressData,
                                        'credit_limit' => floatval($row[15]),
                                        'balance'      => $balance,
                                        'created_at'   => $createdAt
                                    ]
                                );
                                $count++;
                             } catch (\Exception $ex) {
                                 Log::error("Error crítico cliente ID $legacyId: " . $ex->getMessage());
                                 $errors++;
                             }
                        } else {
                            Log::error("Error cliente ID " . ($row[0] ?? '?') . ": " . $e->getMessage());
                            $errors++;
                        }
                    }
                }
            }
        }

        DB::commit();
        fclose($handle);

        return response()->json([
            'status' => 'success',
            'message' => 'Migración de clientes completada (Signo de saldo corregido).',
            'total_processed' => $count,
            'errors' => $errors
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        if (is_resource($handle)) fclose($handle);
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

// --- FUNCIONES AUXILIARES ---

function parseSqlValuesCustomers($string) {
    $rows = [];
    $len = strlen($string);
    $currentVal = '';
    $inQuotes = false;
    $inParentheses = false;
    $row = [];
    
    for ($i = 0; $i < $len; $i++) {
        $char = $string[$i];
        
        if ($char == '(' && !$inQuotes && !$inParentheses) {
            $inParentheses = true;
            $row = [];
            $currentVal = '';
            continue;
        }
        
        if ($char == ')' && !$inQuotes && $inParentheses) {
            $inParentheses = false;
            $row[] = $currentVal;
            $rows[] = $row;
            continue;
        }
        
        if ($char == "'" && ($i == 0 || $string[$i-1] != '\\')) {
            $inQuotes = !$inQuotes;
            continue; 
        }
        
        if ($char == ',' && !$inQuotes && $inParentheses) {
            $row[] = $currentVal;
            $currentVal = '';
            continue;
        }
        
        if ($inParentheses) {
            $currentVal .= $char;
        }
    }
    return $rows;
}

if (!function_exists('cleanValue2')) {
    function cleanValue2($val) {
        return trim($val, "' ");
    }
}

if (!function_exists('parseDate2')) {
    function parseDate2($dateStr) {
        $dateStr = cleanValue2($dateStr);
        if (empty($dateStr) || $dateStr === '0000-00-00') {
            return now();
        }
        try {
            return Carbon::parse($dateStr);
        } catch (\Exception $e) {
            return now();
        }
    }
}