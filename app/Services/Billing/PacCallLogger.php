<?php

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use App\Models\Billing\PacCallLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes sanitized audit rows to pac_call_logs for every operation performed
 * against the PAC (stamp, cancel, upload_csd, authenticate, balance) or the
 * SAT cancelation-status service (cancel_status).
 *
 * SECURITY: stored payloads must NEVER contain the PAC account password,
 * authentication tokens, or binary CSD/private-key content. Callers pass only
 * safe metadata; sanitizeStampPayload() applies a whitelist when the full
 * stamping payload is supplied.
 */
class PacCallLogger
{
    /**
     * Log a call made on behalf of an invoice (stamp, cancel_status, ...).
     */
    public function forInvoice(
        Invoice $invoice,
        string $operation,
        ?string $customid,
        array $payload,
        ?int $statusCode,
        ?array $response,
        float $startMicrotime,
    ): void {
        $invoice->loadMissing('fiscalProfile');

        $this->log(
            $invoice->fiscal_profile_id,
            $invoice->fiscalProfile?->pac_account_id,
            $operation,
            $payload,
            $statusCode,
            $response,
            $startMicrotime,
            $customid,
        );
    }

    /**
     * Log a call with explicit context — used by account-level operations
     * (authenticate, balance) that are not tied to a single invoice.
     */
    public function log(
        ?int $fiscalProfileId,
        ?int $pacAccountId,
        string $operation,
        array $payload = [],
        ?int $statusCode = null,
        ?array $response = null,
        ?float $startMicrotime = null,
        ?string $customid = null,
    ): void {
        try {
            PacCallLog::create([
                'fiscal_profile_id'    => $fiscalProfileId,
                'pac_account_id'       => $pacAccountId,
                'operation'            => $operation,
                'customid'             => $customid,
                'request_payload'      => $payload,
                'response_status_code' => $statusCode,
                'response_body'        => $response,
                'duration_ms'          => $startMicrotime !== null
                    ? (int) round((microtime(true) - $startMicrotime) * 1000)
                    : null,
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break a billing operation.
            Log::warning('Failed to write pac_call_logs row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Keep only safe metadata from the stamping payload (RFC, serie, folio,
     * montos, customid) — drop any binary/sensitive field.
     */
    public function sanitizeStampPayload(array $payload): array
    {
        $safe = [
            'Serie'       => $payload['Serie'] ?? null,
            'Folio'       => $payload['Folio'] ?? null,
            'Fecha'       => $payload['Fecha'] ?? null,
            'TipoDeComprobante' => $payload['TipoDeComprobante'] ?? null,
            'MetodoPago'  => $payload['MetodoPago'] ?? null,
            'SubTotal'    => $payload['SubTotal'] ?? null,
            'Total'       => $payload['Total'] ?? null,
            'Moneda'      => $payload['Moneda'] ?? null,
            'Emisor.Rfc'  => data_get($payload, 'Emisor.Rfc'),
            'Receptor.Rfc' => data_get($payload, 'Receptor.Rfc'),
            'Conceptos.count' => is_countable($payload['Conceptos'] ?? null) ? count($payload['Conceptos']) : null,
        ];

        return array_filter($safe, fn ($v) => $v !== null);
    }
}
