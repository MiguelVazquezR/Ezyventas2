<?php

namespace App\Traits;

use App\Services\TinifyService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Log;

trait OptimizeMediaWithTinify
{
    /**
     * Optimizes a media item using Tinify if conditions are met.
     * Requires TinifyService to be injected in the controller's constructor
     * and assigned to a property named $tinifyService.
     *
     * @param Media $mediaItem The recently saved Media object.
     * @param int $sizeLimitKB The size in KB above which optimization should occur. Default 400KB.
     * @return void
     */
    protected function optimizeAndTrackMedia(Media $mediaItem, int $sizeLimitKB = 400): void
    {
        // Ensure the service is available in the controller using this trait
        if (!isset($this->tinifyService) || !$this->tinifyService instanceof TinifyService) {
            Log::error('TinifyService is not available or correctly injected in the controller using OptimizeMediaWithTinify trait.');
            return;
        }

        try {
            // <-- INICIO DE LA CORRECCIÓN -->

            // 1. Obtener la ruta del objeto Media.
            // El método getPath() te dará la ruta completa al archivo en el disco.
            $path = $mediaItem->getPath();

            // 2. Usar la función nativa de PHP file_exists() sobre la RUTA (no sobre el objeto).
            // Comprobamos si la ruta no está vacía Y si el archivo existe físicamente.
            if (empty($path) || !file_exists($path)) {
                Log::warning("Media file does not exist for optimization: ID {$mediaItem->id}. Path attempt: '{$path}'");
                return;
            }
            // <-- FIN DE LA CORRECCIÓN -->
            
            // Ahora que sabemos que el archivo existe, podemos obtener su tamaño.
            $fileSizeInKB = filesize($path) / 1024;
            
            // Check size, environment
            // (Tu lógica de optimización)
            if ($fileSizeInKB > $sizeLimitKB && app()->environment('local')) {
                // Optional: Check Tinify compression limit
                $currentCompressions = $this->tinifyService->totalCompressions();
                Log::info($currentCompressions);
                if ($currentCompressions !== null && $currentCompressions < 500) {
                    Log::info("OptimizeMediaWithTinify: Optimizing image via Tinify: {$path}");
                    $this->tinifyService->optimizeImage($path); // Se optimiza en el mismo lugar
                } else if ($currentCompressions !== null) {
                    Log::warning("OptimizeMediaWithTinify: Tinify compression limit reached ({$currentCompressions}). Image not optimized: {$path}");
                }
            }
        } catch (\Throwable $e) {
            Log::error("OptimizeMediaWithTinify: Error optimizing media ID {$mediaItem->id}: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        }
    }
}
