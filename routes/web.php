<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Laravel\Jetstream\Agent;
use Illuminate\Http\Request;
use App\Models\Waitlist;

Route::get('/', function () {
    $agent = new Agent();

    if ($agent->isDesktop() || $agent->isLaptop()) {
        return inertia('Welcome');
    } else {
        return inertia('WelcomeMobile');
    }
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/expiring-layaways', [DashboardController::class, 'getExpiringLayaways'])->name('dashboard.expiring-layaways');
    Route::get('/dashboard/upcoming-deliveries', [DashboardController::class, 'getUpcomingDeliveries'])->name('dashboard.upcoming-deliveries'); 

    // --- INICIO: RUTAS DE ONBOARDING ---
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/setup', [OnboardingController::class, 'show'])->name('setup');
        Route::post('/step-1', [OnboardingController::class, 'storeStep1'])->name('store.step1');
        Route::post('/step-2', [OnboardingController::class, 'storeStep2'])->name('store.step2');
        Route::post('/step-3', [OnboardingController::class, 'storeStep3'])->name('store.step3');
        Route::post('/finish', [OnboardingController::class, 'finish'])->name('finish');
    });
});

require __DIR__ . '/web/POS.php';
require __DIR__ . '/web/products.php';
require __DIR__ . '/web/products-stock.php';
require __DIR__ . '/web/import-export.php';
require __DIR__ . '/web/quick-create.php';
require __DIR__ . '/web/promotions.php';
require __DIR__ . '/web/base-catalog.php';
require __DIR__ . '/web/expenses.php';
require __DIR__ . '/web/customers.php';
require __DIR__ . '/web/services.php';
require __DIR__ . '/web/service-orders.php';
require __DIR__ . '/web/quotes.php';
require __DIR__ . '/web/financial-control.php';
require __DIR__ . '/web/cash-registers.php';
require __DIR__ . '/web/cash-register-sessions.php';
require __DIR__ . '/web/cash-register-session-movements.php';
require __DIR__ . '/web/transactions.php';
require __DIR__ . '/web/payments.php';
require __DIR__ . '/web/settings.php';
require __DIR__ . '/web/roles.php';
require __DIR__ . '/web/permissions.php';
require __DIR__ . '/web/users.php';
require __DIR__ . '/web/subscriptions.php';
require __DIR__ . '/web/bank-accounts.php';
require __DIR__ . '/web/branches.php';
require __DIR__ . '/web/switch-branch.php';
require __DIR__ . '/web/print-templates.php';
require __DIR__ . '/web/print.php';
require __DIR__ . '/web/custom-field-definitions.php';
require __DIR__ . '/web/reports.php';
require __DIR__ . '/web/google-auth.php';
require __DIR__ . '/web/categories.php';
require __DIR__ . '/web/brands.php';
require __DIR__ . '/web/providers.php';
require __DIR__ . '/web/expense-categories.php';
require __DIR__ . '/web/super-admin.php';
// borrar despues de migrar en producción
// require __DIR__ . '/web/migrate-products.php';
// require __DIR__ . '/web/migrate-customers.php';
// require __DIR__ . '/web/migrate-transactions.php';

Route::get('/centro-ayuda', function () {
    return Inertia::render('HelpCenter');
})->name('help-center');

//artisan commands -------------------
Route::get('/clear-all', function () {
    Artisan::call('optimize:clear');
    return 'cleared.';
});

Route::get('/storage-link', function () {
    Artisan::call('storage:link');
    return 'cleared.';
});

Route::get('/migrate', function () {
    Artisan::call('migrate');
    return 'migrated!.';
});

Route::get('/fix-so', function () {
    Artisan::call('app:fix-service-order-folios');
    return 'fixed!.';
});

Route::post('/unirse-lista', function (Request $request) {
    // 1. Validar que es un correo y que no esté repetido
    $request->validate([
        'email' => 'required|email|unique:waitlists,email'
    ]);

    // 2. Crear el registro en la base de datos
    Waitlist::create([
        'email' => $request->email
    ]);

    // 3. Responder que todo salió bien
    return response()->json(['message' => 'Guardado con éxito']);
});

// --- RUTA DE LIMPIEZA SOLICITADA ---
Route::get('/cleanup-products-branch-7', function () {
    $targetBranchId = 7;

    // 1. Buscar los productos
    $products = \App\Models\Product::query()
        ->where('branch_id', $targetBranchId)
        ->where('current_stock', '<=', 0) // Sin stock (0 o negativo)
        ->whereDoesntHave('transactionItems', function ($query) use ($targetBranchId) {
            // Verificamos la relación polimórfica (transactionItems)
            // Y nos aseguramos de que la transacción padre no sea de la sucursal 7
            $query->whereHas('transaction', function ($q) use ($targetBranchId) {
                $q->where('branch_id', $targetBranchId);
            });
        })
        ->get();

    $count = $products->count();

    // 2. Eliminar (Iteramos para disparar eventos de Spatie Media Library/ActivityLog si es necesario)
    \Illuminate\Support\Facades\DB::transaction(function () use ($products) {
        foreach ($products as $product) {
            $product->delete();
        }
    });

    return "Limpieza completada: Se han eliminado {$count} productos de la sucursal {$targetBranchId} que no tenían stock ni historial de ventas en dicha sucursal.";
});

// --- RUTA PARA ESTABLECER VENCIMIENTOS (30 DÍAS) ---
Route::get('/fix-layaway-expiration-dates', function () {
    $count = 0;
    
    // Buscamos transacciones pendientes o en apartado (créditos no liquidados)
    // Se procesa en chunks para evitar problemas de memoria con muchos registros
    \App\Models\Transaction::query()
        ->whereIn('status', [
            \App\Enums\TransactionStatus::PENDING, 
        ])
        ->chunkById(200, function ($transactions) use (&$count) {
            foreach ($transactions as $transaction) {
                // Calcular fecha: Fecha de creación + 30 días
                // Usamos copy() para no modificar la instancia original de created_at si fuera necesario
                $newExpirationDate = \Carbon\Carbon::parse($transaction->created_at)->addDays(30);
                
                $transaction->update([
                    'layaway_expiration_date' => $newExpirationDate
                ]);
                
                $count++;
            }
        });

    return "Proceso completado: Se actualizaron las fechas de vencimiento de {$count} ventas pendientes a 30 días posteriores de su creación.";
});

// --- NUEVA RUTA PARA IMPORTAR SERVICIOS DESDE CSV ---
Route::get('/import-android-services-csv', function () {
    // Asume que el archivo se colocó en la carpeta storage/app/
    $path = storage_path('app/PRECIOS GENERALES  - ANDROID.csv');
    
    if (!file_exists($path)) {
        return "El archivo no existe en la ruta: {$path}. Por favor súbelo a la carpeta storage/app/ de tu proyecto.";
    }

    $subscription = \App\Models\Subscription::with('branches')->find(1);
    if (!$subscription) {
        return "Suscripción 1 no encontrada.";
    }

    $branches = $subscription->branches;
    if ($branches->isEmpty()) {
        return "La suscripción no tiene sucursales asociadas.";
    }

    $mainBranch = $branches->first();
    $branchIds = $branches->pluck('id')->toArray();

    // 1. Crear o buscar la categoría principal
    $category = \App\Models\Category::firstOrCreate([
        'subscription_id' => $subscription->id,
        'type' => 'service',
        'name' => 'Reparaciones Android',
    ], [
        'slug' => \Illuminate\Support\Str::slug('Reparaciones Android-' . uniqid()),
        'description' => 'Servicios importados masivamente desde CSV',
        'branch_id' => $mainBranch->id,
    ]);

    // 2. Mapeo de columnas (índice del CSV) a un nombre de Servicio Base legible
    $servicesMap = [
        1 => 'FRP / Cuenta Google',
        2 => 'Desbloqueo PayJoy / MDM',
        3 => 'Cambio de Pantalla (INCELL)',
        4 => 'Cambio de Pantalla (OLED)',
        5 => 'Cambio de Pantalla (Original)',
        6 => 'Cambio de Batería',
        7 => 'Cambio de Tapa Trasera',
        8 => 'Cambio de Centro de Carga',
        9 => 'Cambio de Auricular',
        10 => 'Cambio de Bocina / Altavoz',
        11 => 'Reparación de Lógica',
        12 => 'Cambio de Botón',
        13 => 'Cambio de Lentes de Cámara',
        14 => 'Cambio de Cámara Trasera',
        15 => 'Cambio de Cámara Frontal',
        16 => 'Liberación AT&T',
        17 => 'Liberación Cricket',
        18 => 'Liberación T-Mobile',
        19 => 'Liberación Metro PCS',
        20 => 'Liberación Verizon',
        21 => 'Liberación Tracfone',
        22 => 'Liberación Boost Mobile',
    ];

    // Array maestro para estructurar la info en memoria antes de guardar
    $servicesData = [];
    foreach ($servicesMap as $index => $name) {
        $servicesData[$index] = [
            'name' => $name,
            'variants' => []
        ];
    }

    // 3. Leer y Parsear el archivo CSV
    $handle = fopen($path, 'r');
    $rowIndex = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $rowIndex++;
        
        // Saltamos las dos primeras filas porque son los encabezados
        if ($rowIndex <= 2) {
            continue;
        }

        // Si la fila no tiene nombre de modelo (Columna 0), la omitimos
        if (empty($row[0])) {
            continue;
        }

        $modelName = trim($row[0]);

        // Recorrer las columnas del 1 al 22
        for ($i = 1; $i <= 22; $i++) {
            if (isset($row[$i]) && trim($row[$i]) !== '') {
                $priceStr = trim($row[$i]);
                
                // Limpiamos la cadena de precio (quitamos $, comas, espacios y guiones)
                $priceStr = str_replace(['$', ',', ' ', '-'], '', $priceStr);

                // Si después de limpiar nos queda un número válido y mayor a 0
                if (is_numeric($priceStr) && (float)$priceStr > 0) {
                    $servicesData[$i]['variants'][] = [
                        'name' => $modelName,
                        'price' => (float)$priceStr
                    ];
                }
            }
        }
    }
    fclose($handle);

    $servicesCreatedCount = 0;
    $variantsCreatedCount = 0;

    // 4. Inserción masiva usando transacciones para seguridad
    \Illuminate\Support\Facades\DB::transaction(function () use ($servicesData, $category, $mainBranch, $branchIds, &$servicesCreatedCount, &$variantsCreatedCount) {
        foreach ($servicesData as $data) {
            // Solo creamos el servicio si logramos extraer al menos un modelo con precio válido
            if (count($data['variants']) > 0) {
                
                // A) Crear el servicio base
                $service = \App\Models\Service::create([
                    'category_id' => $category->id,
                    'branch_id' => $mainBranch->id,
                    'name' => $data['name'],
                    'description' => 'Catálogo importado automáticamente desde CSV',
                    'slug' => \Illuminate\Support\Str::slug($data['name'] . '-' . uniqid()),
                    'base_price' => 0, // Precio 0 por defecto ya que los precios reales están en las variantes
                    'duration_estimate' => null,
                    'show_online' => true,
                ]);

                // B) Sincronizar para que se muestre en todas las sucursales de la suscripción
                $service->branches()->sync($branchIds);
                $servicesCreatedCount++;

                // C) Preparar el lote de inserción de variantes (modelos de celular)
                $variantsToInsert = [];
                foreach ($data['variants'] as $variant) {
                    $variantsToInsert[] = [
                        'service_id' => $service->id,
                        'name' => $variant['name'],
                        'price' => $variant['price'],
                        'duration_estimate' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $variantsCreatedCount++;
                }

                // D) Insertar las variantes en bloques (chunks) para cuidar la memoria
                foreach (array_chunk($variantsToInsert, 200) as $chunk) {
                    \App\Models\ServiceVariant::insert($chunk);
                }
            }
        }
    });

    return "✅ Importación completada con éxito. Se crearon {$servicesCreatedCount} Servicios base (Categorías de reparación) y un total de {$variantsCreatedCount} Variantes (Modelos con precio asociado).";
});

// --- NUEVA RUTA PARA IMPORTAR SERVICIOS IPHONE DESDE CSV ---
Route::get('/import-iphone-services-csv', function () {
    // Asume que el archivo se colocó en la carpeta storage/app/
    $path = storage_path('app/PRECIOS GENERALES  - IPHONE.csv');
    
    if (!file_exists($path)) {
        return "El archivo no existe en la ruta: {$path}. Por favor súbelo a la carpeta storage/app/ de tu proyecto.";
    }

    $subscription = \App\Models\Subscription::with('branches')->find(1);
    if (!$subscription) {
        return "Suscripción 1 no encontrada.";
    }

    $branches = $subscription->branches;
    if ($branches->isEmpty()) {
        return "La suscripción no tiene sucursales asociadas.";
    }

    $mainBranch = $branches->first();
    $branchIds = $branches->pluck('id')->toArray();

    // 1. Crear o buscar la categoría principal para iPhone
    $category = \App\Models\Category::firstOrCreate([
        'subscription_id' => $subscription->id,
        'type' => 'service',
        'name' => 'Reparaciones iPhone',
    ], [
        'slug' => \Illuminate\Support\Str::slug('Reparaciones iPhone-' . uniqid()),
        'description' => 'Servicios de Apple importados masivamente desde CSV',
        'branch_id' => $mainBranch->id,
    ]);

    // 2. MAPEO DE COLUMNAS (¡OJO! Ajusta estos números a las columnas de tu CSV de iPhone)
    // Se asume que la columna 0 tiene el nombre del modelo (Ej: "iPhone 13 Pro Max")
    $servicesMap = [
        1 => 'Cambio de Pantalla (Incell / Genérica)',
        2 => 'Cambio de Pantalla (OLED / Calidad Alta)',
        3 => 'Cambio de Pantalla (Original / Pulled)',
        4 => 'Cambio de Batería',
        5 => 'Cambio de Tapa Trasera (Láser)',
        6 => 'Cambio de Centro de Carga',
        7 => 'Cambio de Auricular',
        8 => 'Cambio de Bocina / Altavoz',
        9 => 'Reparación de Lógica / Micro soldadura',
        10 => 'Cambio de Flex de Encendido/Volumen',
        11 => 'Cambio de Lentes de Cámara',
        12 => 'Cambio de Cámara Trasera',
        13 => 'Cambio de Cámara Frontal',
        14 => 'Reparación de Face ID',
        15 => 'Bypass / Cuenta iCloud',
        16 => 'Liberación RSIM / Físico',
        // Agrega o elimina según las columnas reales de tu archivo IPHONE.csv
    ];

    // Array maestro para estructurar la info en memoria
    $servicesData = [];
    foreach ($servicesMap as $index => $name) {
        $servicesData[$index] = [
            'name' => $name,
            'variants' => []
        ];
    }

    // 3. Leer y Parsear el archivo CSV
    $handle = fopen($path, 'r');
    $rowIndex = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $rowIndex++;
        
        // Saltamos las filas de encabezados (Ajusta si en el de iPhone es 1 sola fila en vez de 2)
        if ($rowIndex <= 2) {
            continue;
        }

        // Si la fila no tiene nombre de modelo (Columna 0), la omitimos
        if (empty($row[0])) {
            continue;
        }

        $modelName = trim($row[0]);

        // Recorrer las columnas mapeadas
        foreach (array_keys($servicesMap) as $colIndex) {
            if (isset($row[$colIndex]) && trim($row[$colIndex]) !== '') {
                $priceStr = trim($row[$colIndex]);
                
                // Limpiamos la cadena de precio
                $priceStr = str_replace(['$', ',', ' ', '-'], '', $priceStr);

                // Si después de limpiar nos queda un número válido y mayor a 0
                if (is_numeric($priceStr) && (float)$priceStr > 0) {
                    $servicesData[$colIndex]['variants'][] = [
                        'name' => $modelName,
                        'price' => (float)$priceStr
                    ];
                }
            }
        }
    }
    fclose($handle);

    $servicesCreatedCount = 0;
    $variantsCreatedCount = 0;

    // 4. Inserción masiva
    \Illuminate\Support\Facades\DB::transaction(function () use ($servicesData, $category, $mainBranch, $branchIds, &$servicesCreatedCount, &$variantsCreatedCount) {
        foreach ($servicesData as $data) {
            if (count($data['variants']) > 0) {
                
                // A) Crear el servicio base
                $service = \App\Models\Service::create([
                    'category_id' => $category->id,
                    'branch_id' => $mainBranch->id,
                    'name' => $data['name'],
                    'description' => 'Catálogo Apple importado automáticamente desde CSV',
                    'slug' => \Illuminate\Support\Str::slug($data['name'] . '-' . uniqid()),
                    'base_price' => 0, 
                    'duration_estimate' => null,
                    'show_online' => true,
                ]);

                // B) Sincronizar sucursales
                $service->branches()->sync($branchIds);
                $servicesCreatedCount++;

                // C) Preparar el lote de variantes
                $variantsToInsert = [];
                foreach ($data['variants'] as $variant) {
                    $variantsToInsert[] = [
                        'service_id' => $service->id,
                        'name' => $variant['name'],
                        'price' => $variant['price'],
                        'duration_estimate' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $variantsCreatedCount++;
                }

                // D) Insertar variantes
                foreach (array_chunk($variantsToInsert, 200) as $chunk) {
                    \App\Models\ServiceVariant::insert($chunk);
                }
            }
        }
    });

    return "✅ Importación IPHONE completada con éxito. Se crearon {$servicesCreatedCount} Servicios base y un total de {$variantsCreatedCount} Variantes.";
});