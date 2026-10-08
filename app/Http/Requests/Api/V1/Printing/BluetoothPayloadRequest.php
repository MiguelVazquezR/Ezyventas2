<?php

namespace App\Http\Requests\Api\V1\Printing;

/**
 * ESC/POS payload: the server renders the template and returns the commands
 * ready for the Bluetooth printer.
 */
class BluetoothPayloadRequest extends PrintRequest
{
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'exists:print_templates,id'],
            'data_source_type' => ['required', $this->sourceTypeRule()],
            'data_source_id' => ['required', 'integer'],
            'open_drawer' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'open_drawer' => (bool) $this->validated('open_drawer', false),
        ];
    }
}
