<?php

namespace App\Exceptions\Billing;

/**
 * Timeout or network error where the PAC outcome is unknown.
 *
 * The PAC may or may not have stamped. The reservation must NOT be auto-released:
 * if the PAC actually stamped, a retry would generate a real duplicate (double spend).
 * A background job retries with the same customid/payload; if it stays unresolved,
 * it escalates to manual_review.
 */
class PacTimeoutOrAmbiguousException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?array $response = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
