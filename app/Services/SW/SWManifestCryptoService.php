<?php

namespace App\Services\SW;

use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * SWManifestCryptoService
 *
 * Handles cryptographic operations for the SW manifest signing flow.
 * Specifically: decrypting a FIEL private key (.key) and generating
 * an RSA-SHA256 signature (sello) over the manifest text.
 *
 * SECURITY: The private key and password are used only in-memory
 * during the signing operation and are NEVER persisted to disk,
 * database, or logs. They go out of scope immediately after the
 * signature is generated.
 */
class SWManifestCryptoService
{
    /**
     * Generate an RSA-SHA256 signature of the given content using the
     * FIEL private key.
     *
     * This is equivalent to the SHA256withRSA algorithm used by SAT
     * for CFDI seals and other cryptographic operations.
     *
     * @param string $b64Key    Raw content of the .key file (DER-encoded, encrypted).
     * @param string $password  Password to decrypt the private key.
     * @param string $content   The plaintext content to sign.
     *
     * @return string Base64-encoded signature (sello).
     *
     * @throws \RuntimeException If the key cannot be loaded or signing fails.
     */
    public function generateSignature(string $b64Key, string $password, string $content): string
    {
        try {
            // Load the encrypted private key. PublicKeyLoader in phpseclib 3.x
            // auto-detects the format (DER/PEM, encrypted or not) and uses the
            // password to decrypt it.
            $privateKey = PublicKeyLoader::load($b64Key, $password);

            if (! $privateKey instanceof RSA\PrivateKey) {
                throw new \RuntimeException(
                    'La llave privada no es un objeto RSA válido.'
                );
            }

            // Configure signing: SHA-256 with PKCS#1 v1.5 padding (standard for SAT)
            $privateKey = $privateKey
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1);

            // Sign the content — returns the raw binary signature
            $signature = $privateKey->sign($content);

            // Return Base64-encoded signature for the PAC API
            return base64_encode($signature);

        } catch (\RuntimeException $e) {
            // Re-throw runtime exceptions as-is (they have user-friendly messages)
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Manifest signature generation failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            throw new \RuntimeException(
                'No se pudo generar la firma del manifiesto. Verifica que la llave (.key) y la contraseña de tu FIEL sean correctas.'
            );
        }
    }

    /**
     * Check whether a password successfully decrypts a given private key.
     *
     * Useful for early validation before attempting the full signing flow,
     * so we can show a specific "wrong password" message instead of a
     * generic PAC error.
     *
     * @param string $b64Key    Raw content of the .key file.
     * @param string $password  Password to test.
     *
     * @return bool True if the password decrypts the key successfully.
     */
    public function validatePassword(string $b64Key, string $password): bool
    {
        try {
            $key = PublicKeyLoader::load($b64Key, $password);

            return $key instanceof RSA\PrivateKey;
        } catch (\Throwable) {
            return false;
        }
    }
}
