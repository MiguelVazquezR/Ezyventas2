<?php

namespace App\Exceptions\Billing;

/**
 * The PAC reported a previous stamp for this customid / content.
 *
 * - "307 — El comprobante contiene un timbre previo": the SAME customid +
 *   payload was sent before; the PAC returns the full data (uuid + TFD + CFDI
 *   XML). Recovery is safe and consumes no extra stamp.
 * - "CFDI3307 — customId duplicado": same customid with different content;
 *   returns partial data (uuid + partial TFD, NO cfdi). If the response has no
 *   `cfdi`, it must go to manual review — the full CFDI cannot be auto-recovered.
 */
class PacDuplicateContentException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $response = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
