<?php

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use App\Support\TrackingUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A paid order was rejected or cancelled: says why, and that the payment is refunded.
 */
class OrderCancelled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $restaurant = $this->order->restaurant;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $restaurant->name),
            replyTo: $restaurant->email === null ? [] : [new Address($restaurant->email, $restaurant->name)],
            subject: "Order {$this->order->display_number} is cancelled and refunded",
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing('restaurant');
        $reason = $order->status === OrderStatus::Rejected && $order->rejection_reason !== null
            ? "{$order->restaurant->name} couldn’t make your order: ".rtrim($order->rejection_reason, '.').'.'
            : 'Your order was cancelled.';

        return new Content(
            markdown: 'mail.orders.cancelled',
            with: [
                'order' => $order,
                'restaurant' => $order->restaurant,
                'reason' => $reason,
                'total' => Money::format($order->total_cents),
                'trackingUrl' => TrackingUrl::for($order),
            ],
        );
    }
}
