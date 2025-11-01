<?php

namespace App\Mail;

use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentStatusNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public SubscriptionPayment $payment,
        public string $status, // 'approved' o 'rejected'
        public string $userName,
        public ?string $rejectionReason = null
    ) {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->status === 'approved'
            ? '¡Tu pago ha sido aprobado!'
            : 'Tu pago de suscripción fue rechazado';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-status-notification',
            with: [
                'amount' => $this->payment->amount,
                'status' => $this->status,
                'rejectionReason' => $this->rejectionReason,
                'userName' => $this->userName,
            ]
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
