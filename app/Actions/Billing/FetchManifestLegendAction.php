<?php

namespace App\Actions\Billing;

use App\Models\Billing\FiscalProfile;
use App\Services\SW\SWManifestService;
use Illuminate\Support\Facades\Log;

/**
 * FetchManifestLegendAction
 *
 * Step 1 of the manifest signing flow: fetch the manifest legend
 * (legal text to sign) from SW Sapien using only the FIEL public
 * certificate (.cer). The private key is NOT needed at this stage.
 *
 * The subscriber sees the text and decides whether to authorize
 * before providing their .key and password in step 3.
 */
class FetchManifestLegendAction
{
    public function __construct(
        private readonly SWManifestService $manifestService,
    ) {}

    /**
     * Execute the legend fetch.
     *
     * @return array{status: string, contentB64?: string, message?: string}
     */
    public function execute(FiscalProfile $profile, string $cerContent): array
    {
        // 1. Base64-encode the certificate (in-memory only)
        $b64Cer = base64_encode($cerContent);

        // 2. Optional RFC validation against the certificate
        $cerRfc = $this->extractRfcFromCer($cerContent);
        if ($cerRfc && strtoupper($cerRfc) !== strtoupper($profile->rfc)) {
            return [
                'status'  => 'error',
                'message' => "El RFC del certificado FIEL ({$cerRfc}) no coincide con el RFC del perfil fiscal ({$profile->rfc}). Verifica que estés subiendo la FIEL correcta.",
            ];
        }

        // 3. Call the PAC — only the .cer is sent
        $result = $this->manifestService->fetchLegend($profile, $b64Cer);

        // 4. On success, persist the manifest text for audit trail and step 2 usage.
        //    Trim the base64 to prevent whitespace from causing signature mismatches.
        if ($result['status'] === 'success') {
            $profile->update([
                'manifest_text_b64'      => trim($result['contentB64']),
                'manifest_text_shown_at' => now(),
                // Reset the acceptance timestamp since this is a fresh fetch
                'manifest_text_accepted_at' => null,
            ]);

            Log::info('Manifest legend fetched and stored', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
            ]);
        }

        return $result;
    }

    /**
     * Try to extract the RFC from a PEM/DER X.509 certificate.
     *
     * Parses the Subject field for common RFC patterns.
     * Returns null if extraction fails — the PAC will validate
     * it anyway; this is a helpful pre-check.
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
            Log::info('Could not extract RFC from FIEL certificate', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
