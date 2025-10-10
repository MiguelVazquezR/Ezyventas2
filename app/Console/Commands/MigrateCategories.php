<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Category; // Asegúrate de que este es tu modelo para la tabla 'categories'

class MigrateCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:categories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra las categorías desde la base de datos antigua a la nueva.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando la migración de categorías...');

        // 1. Conectamos a la base de datos antigua
        $oldCategories = DB::connection('mysql_old')->table('categories')->get();

        if ($oldCategories->isEmpty()) {
            $this->warn('No se encontraron categorías en la tabla antigua. No hay nada que migrar.');
            return 0;
        }

        $progressBar = $this->output->createProgressBar($oldCategories->count());
        $progressBar->start();

        foreach ($oldCategories as $oldCategory) {
            // 2. Mapeamos 'business_line_name' al nuevo 'subscription_id'
            $newSubscriptionId = match ((string)$oldCategory->business_line_name) {
                '24', '25' => 2,
                '30' => 3,
                default => null, // Las categorías de catálogo base no tienen subscription_id
            };

            // 3. Usamos updateOrCreate para insertar los datos en la nueva tabla
            Category::updateOrCreate(
                [
                    // Condición para evitar duplicados: misma categoría para la misma suscripción
                    'name' => $oldCategory->name,
                    'subscription_id' => $newSubscriptionId,
                ],
                [
                    'type' => 'product', // Valor por defecto
                    'business_type' => null, // Como se solicitó, este campo será nulo por ahora
                    'created_at' => $oldCategory->created_at,
                    'updated_at' => $oldCategory->updated_at,
                ]
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\n¡Migración de categorías completada exitosamente!");

        return 0;
    }
}