<?php

namespace App\Actions\Billing;

use App\Models\Billing\FiscalProfile;
use App\Services\SW\SWManifestService;
use Illuminate\Support\Facades\Log;

/**
 * SignManifestAction
 *
 * Orchestrates the SW manifest signing flow for a fiscal profile:
 * 1. Validates FIEL file extensions and sizes (handled by FormRequest)
 * 2. Optionally validates that the RFC in the .cer matches the profile's RFC
 * 3. Converts .cer/.key to Base64 in-memory
 * 4. Calls SWManifestService
 * 5. Ensures sensitive data (.key, password) is NEVER persisted or logged
 */
class SignManifestAction
{
    public function __construct(
        private readonly SWManifestService $manifestService,
    ) {}

    /**
     * Execute the manifest signing.
     *
     * @return array{status: string, pdf_path?: string, message?: string}
     */
    public function execute(FiscalProfile $profile, array $data): array
    {
        // 1. Read and encode files (in-memory only, discarded after this method ends)
        $cerContent = file_get_contents($data['cer_file']->getRealPath());
        $keyContent = file_get_contents($data['key_file']->getRealPath());

        // 2. Optional RFC validation: check the certificate's RFC matches the profile
        $cerRfc = $this->extractRfcFromCer($cerContent);
        if ($cerRfc && strtoupper($cerRfc) !== strtoupper($profile->rfc)) {
            return [
                'status'  => 'error',
                'message' => "El RFC del certificado FIEL ({$cerRfc}) no coincide con el RFC del perfil fiscal ({$profile->rfc}). Verifica que estés subiendo la FIEL correcta.",
            ];
        }

        // 3. Base64 encode (in-memory)
        $b64Cer = base64_encode($cerContent);
        $b64Key = base64_encode($keyContent);

        // 4. Call the PAC — password and key are passed but never stored
        $result = $this->manifestService->signManifest(
            $profile,
            $b64Cer,
            $b64Key,
            $data['password'],
            $data['email'] ?? $profile->email,
        );

        // 5. If error, persist the error message for UI display
        if ($result['status'] === 'error') {
            $profile->update([
                'manifest_last_attempt_error' => $result['message'],
            ]);
        }

        // 6. Sensitive data ($b64Key, $password, $keyContent) go out of scope here
        //    and are garbage-collected — nothing persisted to DB, disk, or logs.
        return $result;
    }

    /**
     * Try to extract the RFC from a PEM/DER X.509 certificate.
     *
     * Parses the Subject field for common RFC patterns like:
     *   /O=EMPRESA SA DE CV/OU=RFC123456ABC/...
     *   /CN=RFC: XXXX000101XXX/...
     *
     * Returns null if extraction fails — the PAC will validate
     * it anyway; this is just a helpful pre-check.
     */
    private function extractRfcFromCer(string $cerContent): ?string
    {
        try {
            $cert = openssl_x509_parse($cerContent);
            if (! $cert) {
                return null;
            }

            $subject = $cert['subject'] ?? [];
            $fields = array_merge(
                $subject,
                ['CN' => $subject['CN'] ?? '', 'OU' => $subject['OU'] ?? '']
            );

            // Common patterns in Mexican FIEL certificates:
            // OU often contains the RFC directly
            foreach (['OU', 'CN', 'x500UniqueIdentifier', 'serialNumber'] as $key) {
                $value = $fields[$key] ?? '';
                if (empty($value)) continue;

                // Pattern: standalone RFC (12-13 alphanumeric chars)
                if (preg_match('/\b([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3})\b/i', $value, $m)) {
                    return strtoupper($m[1]);
                }

                // Pattern: "RFC: XXXX000101XXX" or "RFC XXXX000101XXX"
                if (preg_match('/RFC:?\s*([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3})\b/i', $value, $m)) {
                    return strtoupper($m[1]);
                }
            }

            return null;
        } catch (\Throwable $e) {
            // RFC extraction is best-effort; don't fail if parsing fails
            Log::info('Could not extract RFC from FIEL certificate', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
