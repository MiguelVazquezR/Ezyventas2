<?php

namespace App\Mail;

use App\Models\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the subscriber when a pending cancelation is resolved by the SAT:
 * accepted ('canceled'), rejected by the receiver ('rejected') or expired
 * without response ('expired').
 */
class CancelationResolvedNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $result  'canceled' | 'rejected' | 'expired'
     */
    public function __construct(
        public Invoice $invoice,
        public string $result,
    ) {}

    public function envelope(): Envelope
    {
        $folio = $this->invoiceFolio();

        $subject = match ($this->result) {
            'canceled' => "Cancelación aceptada — Factura {$folio}",
            'rejected' => "Cancelación rechazada — Factura {$folio}",
            default    => "Solicitud de cancelación vencida — Factura {$folio}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.cancelation-resolved-notification',
            with: [
                'folio'        => $this->invoiceFolio(),
                'uuid'         => $this->invoice->uuid,
                'receiverName' => $this->invoice->receiver_legal_name,
                'receiverRfc'  => $this->invoice->receiver_rfc,
                'total'        => $this->invoice->total,
                'result'       => $this->result,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * Human-readable folio ("A 123") falling back to the invoice id.
     */
    private function invoiceFolio(): string
    {
        $series = $this->invoice->series ? $this->invoice->series . ' ' : '';
        $folio = trim($series . $this->invoice->folio);

        return $folio !== '' ? $folio : (string) $this->invoice->id;
    }
}
