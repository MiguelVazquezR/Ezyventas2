<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveStampPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Superadmin middleware handles access
    }

    public function rules(): array
    {
        return [];
    }
}
