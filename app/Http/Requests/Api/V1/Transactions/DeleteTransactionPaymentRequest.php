<?php

namespace App\Http\Requests\Api\V1\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting a payment of a sale: the server reverts its bank, balance and cash
 * effects before removing it.
 */
class DeleteTransactionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.edit_payment') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
