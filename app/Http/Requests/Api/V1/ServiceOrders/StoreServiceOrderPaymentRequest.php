<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use App\Http\Requests\Api\V1\Transactions\StoreTransactionPaymentRequest;

/**
 * Advance payment (anticipo) of a service order.
 *
 * The payload is exactly the payload of a sale payment: the server resolves (or
 * creates) the linked sale and books the money there.
 */
class StoreServiceOrderPaymentRequest extends StoreTransactionPaymentRequest {}
