<?php

namespace App\Actions\Billing;

use App\Models\Billing\FiscalProfile;
use App\Services\SW\SWManifestService;
use Illuminate\Support\Facades\Log;

/**
 * SignManifestAction
 *
 * Step 3 of the manifest signing flow: submit the FIEL certificate,
 * private key, and password to the PAC via POST.
 *
 * Per SW Sapien API docs, the PAC handles RSA-SHA256 signing
 * internally — we do NOT pre-compute the signature.
 *
 * Prerequisites:
 *   - Step 1 (FetchManifestLegendAction) must have been completed.
 *   - Step 2 (UI acceptance) should have been completed.
 */
class SignManifestAction
{
    public function __construct(
        private readonly SWManifestService $manifestService,
    ) {}

    /**
     * Execute the manifest signing (step 3).
     *
     * @return array{status: string, pdf_path?: string, message?: string}
     */
    public function execute(FiscalProfile $profile, array $data): array
    {
        // 1. Validate prerequisites — manifest text must have been fetched
        if (empty($profile->manifest_text_b64)) {
            return [
                'status'  => 'error',
                'message' => 'Primero debes obtener el texto del manifiesto. Recarga la página e intenta de nuevo.',
            ];
        }

        // 2. Read and encode the .cer file (in-memory only)
        //    The .cer is re-requested here for simplicity — the subscriber
        //    provides all FIEL files together in step 3.
        $cerContent = file_get_contents($data['cer_file']->getRealPath());
        $b64Cer = base64_encode($cerContent);

        // 3. Optional RFC validation
        $cerRfc = $this->extractRfcFromCer($cerContent);
        if ($cerRfc && strtoupper($cerRfc) !== strtoupper($profile->rfc)) {
            return [
                'status'  => 'error',
                'message' => "El RFC del certificado FIEL ({$cerRfc}) no coincide con el RFC del perfil fiscal ({$profile->rfc}). Verifica que estés subiendo la FIEL correcta.",
            ];
        }

        // 4. Read and encode the .key file (in-memory only — never persisted).
        $keyContent = file_get_contents($data['key_file']->getRealPath());
        $b64Key = base64_encode($keyContent);

        // 5. Submit to the PAC via POST.
        //    Per SW Sapien API docs: the PAC handles the RSA signature
        //    internally. We send B64Cer + B64Key + Password.
        $result = $this->manifestService->submitSignature(
            $profile,
            $b64Cer,
            $b64Key,
            $data['password'],
            $data['email'] ?? $profile->email,
        );

        // 8. If error, persist the error message for UI display
        if ($result['status'] === 'error') {
            $profile->update([
                'manifest_last_attempt_error' => $result['message'],
            ]);
        }

        // 9. Sensitive data ($keyContent, $data['password']) go out of scope here
        //    and are garbage-collected — nothing persisted to DB, disk, or logs.
        return $result;
    }

    /**
     * Try to extract the RFC from a PEM/DER X.509 certificate.
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

            foreach (['OU', 'CN', 'x500UniqueIdentifier', 'serialNumber'] as $key) {
                $value = $fields[$key] ?? '';
                if (empty($value)) continue;

                if (preg_match('/\b([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3})\b/i', $value, $m)) {
                    return strtoupper($m[1]);
                }

                if (preg_match('/RFC:?\s*([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3})\b/i', $value, $m)) {
                    return strtoupper($m[1]);
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::info('Could not extract RFC from FIEL certificate', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
