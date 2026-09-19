<?php

namespace App\Http\Requests\Api\V1\Printing;

/**
 * Template operations (TSPL) used by label printers: the app renders them in
 * the device and adds the offset of the label.
 */
class PrintPayloadRequest extends BluetoothPayloadRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'offset_x' => ['nullable', 'numeric'],
            'offset_y' => ['nullable', 'numeric'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return array_merge(parent::options(), [
            'offset_x' => $this->validated('offset_x') ?? 0,
            'offset_y' => $this->validated('offset_y') ?? 0,
        ]);
    }
}
