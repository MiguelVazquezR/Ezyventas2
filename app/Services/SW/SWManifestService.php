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
 * Uses the TWO-STEP flow:
 *   1. PATCH — fetch the manifest legend (text to sign) using only the .cer.
 *   2. PUT  — submit the signed text (legend + RSA-SHA256 signature).
 *
 * SECURITY: The FIEL private key (.key) and password are NEVER
 * persisted. They are used in-memory only during signature generation,
 * sent to the PAC, and immediately discarded — no traces
 * in logs, queues, or database.
 */
class SWManifestService
{
    /**
     * Step 1 — Fetch the manifest legend (legal text to be signed).
     *
     * This only requires the FIEL public certificate (.cer). The private key
     * is NOT needed at this stage — the subscriber sees the text first and
     * decides whether to authorize it before providing their .key and password.
     *
     * @param FiscalProfile $profile The fiscal profile requesting the legend.
     * @param string        $b64Cer  Base64-encoded .cer file content.
     *
     * @return array{status: string, contentB64: string, message?: string}
     *
     * @throws \RuntimeException When the PAC is unreachable or configuration is missing.
     */
    public function fetchLegend(FiscalProfile $profile, string $b64Cer): array
    {
        $endpoint = $this->resolveManagementEndpoint();
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'El servicio de facturación no está configurado. Contacta con soporte técnico.'
            );
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/manifests';

        $payload = [
            'B64Cer' => $b64Cer,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->timeout(30)
            ->patch($url, $payload);

        } catch (ConnectionException $e) {
            Log::error('SW Manifest legend fetch failed — PAC unreachable', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
            ]);

            throw new \RuntimeException(
                'No se pudo obtener el texto del manifiesto. El servicio no está disponible. Intenta de nuevo.'
            );
        }

        // ── Error response ──
        if ($response->failed()) {
            $json    = $response->json();
            $message = $json['message'] ?? $response->body();

            Log::error('SW Manifest legend fetch rejected by PAC', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'pac_message'       => $message,
            ]);

            return [
                'status'  => 'error',
                'message' => $message,
            ];
        }

        // ── Success response (200): JSON with contentB64 ──
        $json       = $response->json();
        $contentB64 = $json['data']['contentB64'] ?? null;

        if (empty($contentB64)) {
            Log::error('SW Manifest legend response missing contentB64', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'response_keys'     => array_keys($json),
            ]);

            return [
                'status'  => 'error',
                'message' => 'No se pudo obtener el texto del manifiesto. Intenta de nuevo.',
            ];
        }

        return [
            'status'     => 'success',
            'contentB64' => $contentB64,
        ];
    }

    /**
     * Step 2 — Submit the manifest for signing by the PAC.
     *
     * Per SW Sapien API docs (POST /management/v2/api/dealers/manifests):
     * The PAC handles the RSA-SHA256 signature internally. We send the
     * FIEL public cert (B64Cer), private key (B64Key), and password
     * directly. We do NOT pre-compute the signature ourselves.
     *
     * @param FiscalProfile $profile  The fiscal profile being signed for.
     * @param string        $b64Cer   Base64-encoded .cer file content.
     * @param string        $b64Key   Base64-encoded .key file content.
     * @param string        $password Password for the private key.
     * @param string        $email    Email to receive the signed manifest copy.
     *
     * @return array{status: 'success'|'error', pdf_path?: string, message?: string}
     */
    public function submitSignature(
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
                'El servicio de facturación no está configurado. Contacta con soporte técnico.'
            );
        }

        $url = rtrim($endpoint, '/') . '/management/v2/api/dealers/manifests';

        $payload = [
            'B64Cer'    => str_replace(["\r", "\n", ' '], '', $b64Cer),
            'B64Key'    => str_replace(["\r", "\n", ' '], '', $b64Key),
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
            Log::error('SW Manifest signature submission failed — PAC unreachable', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
            ]);

            throw new \RuntimeException(
                'No se pudo completar la firma del manifiesto. El servicio no está disponible. Intenta de nuevo.'
            );
        }

        // ── Error response (400): JSON with message ──
        if ($response->failed()) {
            $json    = $response->json();
            $message = $json['message'] ?? $response->body();

            Log::error('SW Manifest signature rejected by PAC', [
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
        $pdfContent  = $response->body();
        $contentType = $response->header('Content-Type');

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
                'message' => 'No se pudo generar el PDF del manifiesto. Intenta de nuevo.',
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
