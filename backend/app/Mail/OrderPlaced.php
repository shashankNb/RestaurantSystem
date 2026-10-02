<?php

namespace App\Mail;

use App\Enums\FulfilmentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\TrackingUrl;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order confirmation, which is also the customer's tax invoice: menu prices include
 * GST, and an Australian tax invoice under $1,000 needs the seller's name and ABN, the
 * date, what was sold, and the GST included.
 */
class OrderPlaced extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $restaurant = $this->order->restaurant;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $restaurant->name),
            replyTo: $restaurant->email === null ? [] : [new Address($restaurant->email, $restaurant->name)],
            subject: "Order {$this->order->display_number} from {$restaurant->name}",
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing(['restaurant', 'items.modifiers']);
        $restaurant = $order->restaurant;
        $placedAt = CarbonImmutable::parse($order->placed_at ?? $order->created_at)->setTimezone($restaurant->timezone);

        return new Content(
            markdown: 'mail.orders.placed',
            with: [
                'order' => $order,
                'restaurant' => $restaurant,
                'firstName' => strtok($order->customer_name, ' ') ?: $order->customer_name,
                'when' => $this->fulfilmentSummary($order, $placedAt),
                'placedAt' => $placedAt->format('j F Y, g:i a'),
                'trackingUrl' => TrackingUrl::for($order),
                'lines' => $order->items->map(fn (OrderItem $item): array => [
                    'name' => $item->name,
                    'options' => $item->modifiers->map(fn (OrderItemModifier $modifier): string => $modifier->name)->implode(', '),
                    'quantity' => $item->quantity,
                    'total' => Money::format($item->line_total_cents),
                ])->all(),
                'subtotal' => Money::format($order->subtotal_cents),
                'deliveryFee' => $order->delivery_fee_cents > 0 ? Money::format($order->delivery_fee_cents) : null,
                'discount' => $order->discount_cents > 0 ? Money::format(-$order->discount_cents) : null,
                'total' => Money::format($order->total_cents),
                'gst' => Money::format($order->gst_cents),
            ],
        );
    }

    /** "Pickup, as soon as possible", "Dine in at table 12, as soon as possible" or "Delivery to Southbank 3006, today at 7 pm". */
    private function fulfilmentSummary(Order $order, CarbonImmutable $placedAt): string
    {
        $how = match ($order->fulfilment_type) {
            FulfilmentType::Delivery => trim("Delivery to {$order->delivery_suburb} {$order->delivery_postcode}"),
            FulfilmentType::DineIn => "Dine in at table {$order->table_label}",
            FulfilmentType::Pickup => 'Pickup',
        };
        $time = $order->scheduled_for === null
            ? 'as soon as possible'
            : LocalTime::describe(CarbonImmutable::parse($order->scheduled_for), $order->restaurant->timezone, $placedAt);

        return "{$how}, {$time}";
    }
}
