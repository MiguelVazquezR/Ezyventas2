<?php

namespace Ezyventas\AiAgent\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAiMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Escribe un mensaje para el asistente.',
            'message.max'      => 'El mensaje no puede exceder los 2,000 caracteres.',
        ];
    }
}
