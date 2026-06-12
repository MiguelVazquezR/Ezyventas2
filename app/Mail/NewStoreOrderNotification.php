<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewStoreOrderNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        $storeName = $this->order->storeConfig?->store_name ?? 'Tu tienda';

        return new Envelope(
            subject: "Nuevo pedido — {$storeName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.new-store-order',
            with: [
                'orderNumber' => $this->order->formatted_order_number,
                'customerName' => $this->order->customer_name,
                'customerPhone' => $this->order->customer_phone,
                'total' => number_format($this->order->total, 2),
                'items' => $this->order->items->map(fn($i) => [
                    'name' => $i->product_name,
                    'quantity' => $i->quantity,
                    'price' => number_format($i->unit_price, 2),
                ]),
                'deliveryType' => $this->order->delivery_type === 'pickup' ? 'Recoger en tienda' : 'Envío a domicilio',
                'paymentMethod' => $this->order->payment_method === 'mercadopago' ? 'Mercado Pago' : 'Efectivo',
                'storeName' => $this->order->storeConfig?->store_name ?? 'Tu tienda',
            ],
        );
    }
}
