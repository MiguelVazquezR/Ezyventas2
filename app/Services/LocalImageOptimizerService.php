<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Servicio para optimizar imágenes localmente usando la extensión GD de PHP.
 * Actúa como un reemplazo "drop-in" para TinifyService.
 */
class LocalImageOptimizerService
{
    /**
     * Optimiza una imagen sobrescribiéndola con una calidad reducida.
     *
     * @param string $sourcePath Ruta al archivo de imagen.
     * @param string|null $destinationPath Opcional. Si es null, sobrescribe el original.
     * @return bool True si tuvo éxito, false si falló.
     */
    public function optimizeImage($sourcePath, $destinationPath = null): bool
    {
        if (!file_exists($sourcePath)) {
            Log::error("LocalImageOptimizer: No se puede optimizar. El archivo no existe en: '{$sourcePath}'.");
            return false;
        }

        // Si el destino es null, es la misma ruta de origen
        $destinationPath = $destinationPath ?? $sourcePath;

        try {
            $imageInfo = getimagesize($sourcePath);
            if (!$imageInfo) {
                Log::warning("LocalImageOptimizer: No se pudo leer la información de la imagen: '{$sourcePath}'.");
                return false;
            }

            $mimeType = $imageInfo['mime'];
            $image = null;
            $success = false;

            // Cargar la imagen en memoria según su tipo
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($sourcePath);
                    if ($image) {
                        // Guardar el JPEG con calidad del 75% (buen balance)
                        $success = imagejpeg($image, $destinationPath, 75);
                    }
                    break;

                case 'image/png':
                    $image = @imagecreatefrompng($sourcePath);
                    if ($image) {
                        // Habilitar transparencia
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        // Guardar el PNG con máxima compresión (es sin pérdida, nivel 9)
                        $success = imagepng($image, $destinationPath, 9);
                    }
                    break;
                
                case 'image/webp':
                    $image = @imagecreatefromwebp($sourcePath);
                    if ($image) {
                        // Guardar el WebP con calidad del 80%
                        $success = imagewebp($image, $destinationPath, 80);
                    }
                    break;

                default:
                    Log::warning("LocalImageOptimizer: Tipo de archivo no soportado ('{$mimeType}') para: '{$sourcePath}'.");
                    return false;
            }

            // Liberar memoria
            if ($image) {
                imagedestroy($image);
            }

            if ($success) {
                Log::info("LocalImageOptimizer: Imagen optimizada con éxito: {$destinationPath}");
                return true;
            } else {
                Log::error("LocalImageOptimizer: No se pudo crear la imagen desde '{$sourcePath}'.");
                return false;
            }

        } catch (\Exception $e) {
            Log::error("LocalImageOptimizer: Excepción al optimizar '{$sourcePath}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Método "dummy" para imitar a TinifyService.
     * Como no tenemos límite de compresión, siempre devolvemos 0.
     * Esto hará que el chequeo `if ($currentCompressions < 500)` en tu Trait siempre sea verdadero.
     *
     * @return int
     */
    public function totalCompressions(): ?int
    {
        // Siempre permitimos la compresión
        return 0;
    }
}