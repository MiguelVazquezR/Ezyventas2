<?php

namespace App\Exceptions\Billing;

/**
 * Thrown when there are not enough stamps available to stamp an invoice.
 *
 * In the orchestrated flow this is raised BEFORE creating the reservation,
 * so no stamp is reserved and the folio counter is left untouched.
 */
class InsufficientStampsException extends \RuntimeException
{
}
