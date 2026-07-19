<?php

namespace App\Services\SW;

use App\Models\Billing\FiscalProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SWManifestService
 *
 * Handles the SW Sapien Manifiesto (regulatory authorization)
 * signing flow for a fiscal profile.
 *
 * The Manifiesto is a one-time signature that each RFC must provide
 * to authorize SW Sapien to deliver CFDI copies to the SAT
 * (RMF rule 2.7.2.7). It uses the FIEL (e.firma), NOT the CSD.
 *
 * SECURITY: The FIEL private key (.key) and password are NEVER
 * persisted. They are converted to Base64 in-memory, sent to the
 * PAC, and immediately discarded after the response — no traces
 * in logs, queues, or database.
 */
class SWManifestService
{
    /**
     * Sign the SW manifest for a fiscal profile using its FIEL.
     *
     * @param FiscalProfile $profile  The fiscal profile to sign for.
     * @param string        $b64Cer   Base64-encoded .cer file content.
     * @param string        $b64Key   Base64-encoded .key file content (discarded after call).
     * @param string        $password FIEL password (discarded after call).
     * @param string        $email    Email to receive the signed manifest copy.
     *
     * @return array{status: 'success'|'error', pdf_path?: string, message?: string}
     *
     * @throws \RuntimeException When the PAC is unreachable or configuration is missing.
     */
    public function signManifest(
        FiscalProfile $profile,
        string $b64Cer,
        string $b64Key,
        string $password,
        string $email,
    ): array {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'SW Sapien Management no está configurado. Define SW_SAPIEN_MANAGEMENT_ENDPOINT y SW_SAPIEN_TOKEN en .env.'
            );
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/manifests';

        $payload = [
            'B64Cer'    => $b64Cer,
            'B64Key'    => $b64Key,
            'Password'  => $password,
            'SendEmail' => true,
            'Email'     => $email,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/pdf, application/json',
            ])
            ->timeout(30)
            ->post($url, $payload);

        } catch (ConnectionException $e) {
            // Log only the fact that it failed — no payload data
            Log::error('SW Manifest signing failed — PAC unreachable', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
            ]);

            throw new \RuntimeException(
                'No se pudo completar la firma. El PAC no está disponible. Intenta de nuevo.'
            );
        }

        // ── Error response (400): JSON with message ──
        if ($response->failed()) {
            $json  = $response->json();
            $message = $json['message'] ?? $response->body();

            // Log only the error message from the PAC — never the FIEL data
            Log::error('SW Manifest signing rejected by PAC', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'pac_message'       => $message,
            ]);

            return [
                'status'  => 'error',
                'message' => $message,
            ];
        }

        // ── Success response (200): binary PDF ──
        $pdfContent    = $response->body();
        $contentType   = $response->header('Content-Type');

        // Sanity check: we expect a PDF
        if (empty($pdfContent) || (is_string($contentType) && ! str_contains($contentType, 'pdf') && strlen($pdfContent) < 100)) {
            Log::error('SW Manifest response was not a valid PDF', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'content_type'      => $contentType,
                'body_length'       => strlen($pdfContent),
            ]);

            return [
                'status'  => 'error',
                'message' => 'El PAC no devolvió un PDF válido. Intenta de nuevo.',
            ];
        }

        // Store the PDF
        $pdfPath = 'manifests/' . $profile->id . '/manifiesto_firmado.pdf';
        Storage::disk('public')->put($pdfPath, $pdfContent);

        // Update the profile — only success metadata, never FIEL data
        $profile->update([
            'manifest_signed_at'           => now(),
            'manifest_pdf_path'            => $pdfPath,
            'manifest_sent_to_email'       => $email,
            'manifest_last_attempt_error'  => null,
        ]);

        Log::info('SW Manifest signed successfully', [
            'fiscal_profile_id' => $profile->id,
            'rfc'               => $profile->rfc,
        ]);

        return [
            'status'   => 'success',
            'pdf_path' => $pdfPath,
        ];
    }

    /**
     * Resolve the management endpoint URL (same pattern as SWUserService).
     */
    private function resolveManagementEndpoint(): string
    {
        return config('services.swsapien.management_endpoint')
            ?: config('services.swsapien.endpoint', 'https://services.test.sw.com.mx');
    }
}
