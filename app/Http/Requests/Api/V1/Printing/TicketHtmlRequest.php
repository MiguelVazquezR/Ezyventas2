<?php

namespace App\Http\Requests\Api\V1\Printing;

/**
 * HTML version of the ticket: fallback for phones without a Bluetooth printer
 * (share as PDF or open in the browser).
 */
class TicketHtmlRequest extends PrintRequest
{
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'exists:print_templates,id'],
            'data_source_type' => ['required', $this->sourceTypeRule()],
            'data_source_id' => ['required', 'integer'],
        ];
    }
}
