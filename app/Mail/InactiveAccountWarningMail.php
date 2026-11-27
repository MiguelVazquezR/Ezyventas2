<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Warning sent a few days before an inactive account is purged.
 */
class InactiveAccountWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subscriptionName,
        public string $purgeDate,
        public int $daysUntilPurge,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu información se eliminará en ' . $this->daysUntilPurge . ' días — ' . $this->subscriptionName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.inactive-account-warning',
            with: [
                'subscriptionName' => $this->subscriptionName,
                'purgeDate' => $this->purgeDate,
                'daysUntilPurge' => $this->daysUntilPurge,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
