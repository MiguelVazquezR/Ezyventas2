<?php

namespace App\Services;

use Tinify\Source;
use Tinify\Tinify;
use Tinify\AccountException;
use Tinify\ClientException; // Import other relevant exceptions
use Tinify\ServerException;
use Tinify\ConnectionException;
use Exception;
use Illuminate\Support\Facades\Log; // Use Log facade

class TinifyService
{
    public function __construct()
    {
        // Ensure API key exists before setting
        $apiKey = env('TINIFY_API_KEY');
        if (!empty($apiKey)) {
            Tinify::setKey($apiKey);
        } else {
            Log::error('TINIFY_API_KEY is not set in the environment file.');
        }
    }

    /**
     * Optimizes an image file. If destinationPath is null, overwrites the source file.
     *
     * @param string $sourcePath Path to the source image file.
     * @param string|null $destinationPath Optional path to save the optimized image.
     * @return bool True on success, false on failure.
     */
    public function optimizeImage($sourcePath, $destinationPath = null): bool
    {
        // Check if API key was set
         if (empty(env('TINIFY_API_KEY'))) {
            Log::error('Cannot optimize image: TINIFY_API_KEY is missing.');
            return false;
        }

        // Check if source file exists
        if (!file_exists($sourcePath)) {
             Log::error("Cannot optimize image: Source file does not exist at path '{$sourcePath}'.");
             return false;
        }


        try {
            $source = Source::fromFile($sourcePath);
            $source->toFile($destinationPath ?? $sourcePath);
            Log::info("Image successfully optimized: {$sourcePath}");
            return true; // Indicate success
        } catch (AccountException $e) {
            Log::error("Tinify Account Error: " . $e->getMessage() . " - Have you exceeded your compression limit?");
            // Potentially disable further compression attempts for this month
        } catch (ClientException $e) {
            Log::error("Tinify Client Error: " . $e->getMessage() . " - Check source file ('{$sourcePath}') format/permissions.");
        } catch (ServerException $e) {
            Log::error("Tinify Server Error: " . $e->getMessage() . " - Temporary issue, maybe retry later?");
        } catch (ConnectionException $e) {
            Log::error("Tinify Connection Error: " . $e->getMessage() . " - Check network connectivity.");
        } catch (Exception $e) { // Catch any other generic exception
            Log::error("General Error during image optimization ('{$sourcePath}'): " . $e->getMessage());
        }
        return false; // Indicate failure
    }

    /**
     * Gets the number of compressions performed this month.
     * Forces API validation if the count hasn't been fetched yet.
     * Returns null if there's an error retrieving the count.
     *
     * @return int|null The compression count or null on error.
     */
    public function totalCompressions(): ?int
    {
         // Check if API key was set
         if (empty(env('TINIFY_API_KEY'))) {
            Log::error('Cannot get compression count: TINIFY_API_KEY is missing.');
            return null;
        }

        try {
            // 1. Obtener el conteo actual (puede ser null si no se ha hecho request)
            $count = Tinify::getCompressionCount();

            // 2. Si es null, forzar una validación de API.
            // Esto hace una llamada a la API, y la librería de Tinify
            // (internamente) leerá el header 'Compression-Count' de la respuesta
            // y actualizará la variable estática.
            if ($count === null) {
                Log::info('Tinify compression count is null, running validation to fetch count...');
                
                // Usamos \Tinify\validate() para llamar a la función en el namespace raíz de Tinify
                // (basado en el archivo que pegaste)
                \Tinify\validate(); 

                // 3. Volver a obtener el conteo, que ahora debería estar poblado.
                $count = Tinify::getCompressionCount();
            }

            return $count;

        } catch (AccountException $e) {
             // Si la validación falla por límite (429), la librería no lanza excepción,
             // pero sí actualiza el conteo. Si es otro error de cuenta (ej. API Key inválida), loguear.
            Log::error("Tinify Account Error while validating: " . $e->getMessage());
            return null; // Retorna null si la API key es inválida, etc.
        } catch (Exception $e) {
            // Capturar cualquier otro error (conexión, etc.) durante la validación
            Log::error("Error retrieving/validating Tinify compression count: " . $e->getMessage());
            return null;
        }
    }
}
