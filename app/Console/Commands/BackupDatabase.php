<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crear un respaldo de la base de datos MySQL y eliminar los más antiguos de 5 días.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando respaldo de la base de datos...');

        // 1. Configuración de la base de datos
        $config = config('database.connections.mysql');
        $dbUser = $config['username'];
        $dbPass = $config['password'];
        $dbName = $config['database'];
        $dbHost = $config['host'];
        $dbPort = $config['port'];

        // Lee la ruta de mysqldump desde .env
        // En local (Windows), añade: MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe"
        // En producción (Linux), no añadas nada. Usará 'mysqldump' por defecto.
        $mysqldumpPath = env('MYSQLDUMP_PATH', 'mysqldump');

        // 2. Configuración del archivo de respaldo
        $backupPath = storage_path('app/backup');
        $fileName = 'backup-' . now()->format('Y-m-d-H-i-s') . '.sql';
        $filePath = $backupPath . '/' . $fileName;

        try {
            // 3. Asegurar que el directorio de respaldo exista
            if (!File::exists($backupPath)) {
                File::makeDirectory($backupPath, 0755, true);
            }

            // 4. Construir el comando de mysqldump con redirección de salida
            // Usamos escapeshellarg() para seguridad
            $command = sprintf(
                '%s -h%s -P%s -u%s %s > %s',
                escapeshellarg($mysqldumpPath), // Usa la ruta del .env o 'mysqldump'
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );

            // Usamos fromShellCommandline para interpretar la redirección (>)
            $process = Process::fromShellCommandline($command);
            
            // Usamos setEnv para pasar la contraseña de forma segura
            $process->setEnv(['MYSQL_PWD' => $dbPass]);
            $process->setTimeout(3600); // Darle tiempo (1 hora) para bases de datos grandes

            // 5. Ejecutar el proceso
            $process->mustRun(); // Lanza una ProcessFailedException si el comando falla

            $this->info("Respaldo completado con éxito: {$filePath}");
            Log::info("Respaldo de base de datos creado: {$fileName}");

            // --- INICIO: Limpieza de respaldos antiguos ---
            $this->info('Iniciando limpieza de respaldos antiguos (anteriores a 5 días)...');
            $files = File::files($backupPath);
            $cutoffTime = now()->subDays(5);
            $deletedCount = 0;

            foreach ($files as $file) {
                // Asegurarse de que es un archivo SQL
                if (strtolower($file->getExtension()) == 'sql') {
                    // Comprobar la fecha de modificación
                    if (File::lastModified($file) < $cutoffTime->getTimestamp()) {
                        File::delete($file->getPathname());
                        $deletedCount++;
                    }
                }
            }

            if ($deletedCount > 0) {
                $this->info("Limpieza completada: Se eliminaron {$deletedCount} respaldos antiguos.");
                Log::info("Limpieza de respaldos: Se eliminaron {$deletedCount} archivos antiguos.");
            } else {
                $this->info('No se encontraron respaldos antiguos para eliminar.');
            }
            // --- FIN: Limpieza de respaldos antiguos ---


        } catch (ProcessFailedException $e) { // Capturar el error específico del proceso
            $this->error('El respaldo de la base de datos falló.');
            $this->error($e->getMessage());
            Log::error("Fallo el respaldo de la base de datos: " . $e->getMessage());
            
            // Limpiar archivo fallido si existe
            if (File::exists($filePath)) {
                File::delete($filePath);
            }
        } catch (\Exception $e) { // Capturar cualquier otro error general
             $this->error('Ocurrió un error inesperado:');
             $this->error($e->getMessage());
             Log::error("Error inesperado en db:backup: " . $e->getMessage());
        }
    }
}