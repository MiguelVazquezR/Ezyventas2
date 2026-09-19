<?php

namespace App\Http\Requests\Api\V1\Printing;

/**
 * Ticket data ready to be sent through WhatsApp.
 *
 * No template is needed: the payload is the same text the web sends, so both
 * clients produce identical messages.
 */
class WhatsAppTicketRequest extends PrintRequest
{
    public function rules(): array
    {
        return [
            'data_source_type' => ['required', $this->sourceTypeRule()],
            'data_source_id' => ['required', 'integer'],
        ];
    }
}
