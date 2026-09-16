<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily preventive alert email (Fase 3, T304/T305): low stamp balance and/or
 * CSD about to expire (or already expired) for one subscription.
 *
 * Each issue is an array:
 *  - ['type' => 'low_balance', 'rfc' => ..., 'balance' => int, 'threshold' => int]
 *  - ['type' => 'csd_expiring', 'rfc' => ..., 'days_left' => int, 'valid_to' => 'Y-m-d']
 *  - ['type' => 'csd_expired', 'rfc' => ..., 'valid_to' => 'Y-m-d']
 */
class PreventiveBillingAlertNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        public string $subscriptionName,
        public array $issues,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Avisos de facturación — ' . $this->subscriptionName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.preventive-billing-alerts',
            with: [
                'subscriptionName' => $this->subscriptionName,
                'issues'           => $this->issues,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
