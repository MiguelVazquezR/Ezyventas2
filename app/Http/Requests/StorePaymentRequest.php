<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_date' => 'required|date',
            'status' => ['required', Rule::enum(PaymentStatus::class)],
            'notes' => 'nullable|string',
        ];
    }
}