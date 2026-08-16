<?php

namespace App\Exceptions\Billing;

/**
 * Clear CFDI validation error from the PAC (bad format, invalid RFC, etc.).
 *
 * NOT ambiguous: the PAC rejected the request and did not consume a stamp.
 * The reservation is released and the invoice returns to DRAFT.
 */
class PacValidationException extends \RuntimeException
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
