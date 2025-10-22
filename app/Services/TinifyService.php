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
            // *** CORRECTION: Return the value ***
            return Tinify::getCompressionCount();
        } catch (Exception $e) {
            Log::error("Error retrieving Tinify compression count: " . $e->getMessage());
            return null; // Return null on error
        }
    }
}