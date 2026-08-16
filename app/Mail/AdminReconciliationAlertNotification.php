<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta al superadmin cuando la reconciliación diaria de una cuenta
 * "normal" detecta una diferencia entre el saldo esperado local y el
 * saldo real del PAC.
 */
class AdminReconciliationAlertNotification extends Mailable
{
    use Queueable, SerializesModels;

    public int $accountId;
    public string $subscriptionName;
    public int $expected;
    public int $real;
    public int $difference;

    public function __construct(
        int $accountId,
        string $subscriptionName,
        int $expected,
        int $real,
        int $difference,
    ) {
        $this->accountId = $accountId;
        $this->subscriptionName = $subscriptionName;
        $this->expected = $expected;
        $this->real = $real;
        $this->difference = $difference;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Alerta de conciliación de timbres (cuenta normal)',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.reconciliation-alert',
            with: [
                'accountId'        => $this->accountId,
                'subscriptionName' => $this->subscriptionName,
                'expected'         => $this->expected,
                'real'             => $this->real,
                'difference'       => $this->difference,
            ],
        );
    }
}
