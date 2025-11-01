<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue; // Importante para encolar el correo
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Number; // Para formatear moneda

class AdminNewPaymentNotification extends Mailable //implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $subscriptionName;
    public float $paymentAmount;
    public string $reviewUrl;

    /**
     * Create a new message instance.
     * Pasamos los datos ya listos para la plantilla.
     */
    public function __construct(string $subscriptionName, float $paymentAmount, string $reviewUrl)
    {
        $this->subscriptionName = $subscriptionName;
        $this->paymentAmount = $paymentAmount;
        $this->reviewUrl = $reviewUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuevo pago de suscripción',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Apuntamos a la nueva plantilla markdown
        return new Content(
            markdown: 'emails.new-payment-notification',
            with: [
                'subscriptionName' => $this->subscriptionName,
                // Formateamos el monto directamente aquí para la plantilla
                'formattedAmount' => "$".number_format($this->paymentAmount, 2),
                'reviewUrl' => $this->reviewUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}