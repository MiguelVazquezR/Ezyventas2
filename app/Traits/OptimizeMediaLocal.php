<?php

namespace App\Traits;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Log;

/**
 * Trait para optimizar imágenes localmente.
 * Intenta usar Imagick (alta calidad) y, si no está disponible,
 * usa GD (calidad estándar) como fallback.
 */
trait OptimizeMediaLocal
{
    /**
     * Optimiza un objeto Media localmente.
     *
     * @param Media $mediaItem El objeto Media recién guardado.
     * @param int $sizeLimitKB El tamaño en KB por encima del cual se debe optimizar.
     * @return void
     */
    protected function optimizeMediaLocal(Media $mediaItem, int $sizeLimitKB = 400): void
    {
        try {
            $path = $mediaItem->getPath();

            if (empty($path) || !file_exists($path)) {
                Log::warning("OptimizeMediaLocal: El archivo no existe: {$path}");
                return;
            }

            $fileSizeInKB = filesize($path) / 1024;

            // No optimizar si está por debajo del límite o no estamos en producción
            // --- NOTA: Revertido a 'production' para uso normal ---
            if ($fileSizeInKB <= $sizeLimitKB || app()->environment('production')) {
                 // Nota: Si necesitas probar en local, cambia 'production' a 'development' temporalmente
                return;
            }

            // --- Lógica de Selección de Optimizador ---

            if (extension_loaded('imagick')) {
                // --- MÉTODO 1: Imagick (Preferido, alta calidad) ---
                $this->optimizeWithImagick($path);

            } else if (extension_loaded('gd')) {
                // --- MÉTODO 2: GD (Fallback, calidad estándar) ---
                $this->optimizeWithGD($path);

            } else {
                Log::error("OptimizeMediaLocal: No se encontró la extensión Imagick ni GD. No se puede optimizar la imagen: {$path}");
            }

        } catch (\Throwable $e) {
            Log::error("OptimizeMediaLocal: Error optimizando media ID {$mediaItem->id}: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        }
    }

    /**
     * Lógica de optimización usando Imagick.
     * @param string $path Ruta al archivo.
     */
    private function optimizeWithImagick(string $path): void
    {
        try {
            Log::info("OptimizeMediaLocal: Optimizando con Imagick: {$path}");
            $image = new \Imagick($path);
            $mimeType = strtolower($image->getImageMimeType());

            switch ($mimeType) {
                case 'image/x-jpeg':
                case 'image/jpeg':
                    // --- INICIO DE LA MEJORA ---
                    $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    
                    // 1. ESTA ES LA CLAVE: Submuestreo de Croma (Chroma Subsampling)
                    // Esto replica la técnica de Tinify para un gran ahorro de espacio
                    // con mínima pérdida de calidad percibida.
                    // '2x2', '1x1', '1x1' equivale a 4:2:0
                    $image->setSamplingFactors(['2x2', '1x1', '1x1']);

                    // 2. Establecer entrelazado para carga progresiva
                    $image->setInterlaceScheme(\Imagick::INTERLACE_JPEG);

                    // 3. Calidad (80-82 es un excelente balance con subsampling)
                    $image->setImageCompressionQuality(80);
                    // --- FIN DE LA MEJORA ---
                    break;
                case 'image/png':
                    // La cuantización de PNG ya es una técnica avanzada, la mantenemos.
                    $image->quantizeImage(256, \Imagick::COLORSPACE_RGB, 0, false, false);
                    $image->setImageCompression(\Imagick::COMPRESSION_ZIP);
                    $image->setOption('png:compression-level', 9);
                    break;
                case 'image/webp':
                    $image->setImageFormat('webp');
                    $image->setOption('webp:method', '6');
                    $image->setImageCompressionQuality(80);
                    break;
                default:
                    Log::warning("OptimizeMediaLocal (Imagick): Tipo de archivo no soportado ('{$mimeType}'): {$path}");
                    $image->clear();
                    return;
            }

            $image->stripImage(); // Quitar metadata EXIF, etc.
            $image->writeImage($path);
            $image->clear();

        } catch (\ImagickException $e) {
            Log::error("OptimizeMediaLocal (Imagick): Error al procesar: " . $e->getMessage());
            // Si Imagick falla (ej. con un GIF animado), intentar con GD
            if (extension_loaded('gd')) {
                Log::warning("OptimizeMediaLocal (Imagick): Fallback a GD para: {$path}");
                $this->optimizeWithGD($path);
            }
        }
    }

    /**
     * Lógica de optimización usando GD.
     * @param string $path Ruta al archivo.
     */
    private function optimizeWithGD(string $path): void
    {
        Log::info("OptimizeMediaLocal: Optimizando con GD (fallback): {$path}");
        $imageInfo = getimagesize($path);
        if (!$imageInfo) return;

        $mimeType = $imageInfo['mime'];

        try {
            switch ($mimeType) {
                case 'image/x-jpeg':
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($path);
                    imagejpeg($sourceImage, $path, 75); // Calidad 75
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($path);
                    imagesavealpha($sourceImage, true);
                    imagepng($sourceImage, $path, 9); // Compresión 9 (máxima)
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($path);
                    imagewebp($sourceImage, $path, 80); // Calidad 80
                    break;
                default:
                    Log::warning("OptimizeMediaLocal (GD): Tipo de archivo no soportado ('{$mimeType}'): {$path}");
                    return;
            }

            if (isset($sourceImage)) {
                imagedestroy($sourceImage);
            }

        } catch (\Exception $e) {
            Log::error("OptimizeMediaLocal (GD): Error al procesar: " . $e->getMessage());
        }
    }
}