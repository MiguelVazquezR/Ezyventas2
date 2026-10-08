<?php

namespace App\Exceptions\Pos;

use RuntimeException;

/**
 * The money sent to settle a sale is bigger than the balance it settles.
 *
 * Cash is the only exception and it is handled before this exception is thrown:
 * the extra money is the change handed back to the customer.
 *
 * Both endpoints answer the same way: `422` with `code: payment_exceeds_pending`
 * and the message below in Spanish.
 */
class PaymentExceedsPendingBalanceException extends RuntimeException
{
    public const CODE = 'payment_exceeds_pending';

    public const MESSAGE = 'El monto total del pago excede el saldo pendiente.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
