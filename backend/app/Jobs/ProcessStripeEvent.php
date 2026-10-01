<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a verified, stored Stripe event. Each event is stored once (its ID is unique),
 * and processed once: a replay finds it already processed and stops. The webhook runs it
 * straight away, and queues it only to retry after a failure.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    /** The events the platform acts on; others are acknowledged and ignored. */
    public const HANDLED = ['payment_intent.succeeded', 'payment_intent.payment_failed'];

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public readonly int $stripeEventId) {}

    public function handle(OrderService $orders): void
    {
        $event = StripeEvent::query()->find($this->stripeEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        /** @var array{id?: string, amount_received?: int, metadata?: array{order_public_id?: string}} $intent */
        $intent = $event->payload['data']['object'] ?? [];
        $order = $this->orderFor($intent);

        if ($order === null) {
            Log::warning('Stripe event for an unknown order.', ['event' => $event->stripe_event_id, 'type' => $event->type]);
        } elseif ($event->type === 'payment_intent.succeeded') {
            $orders->markPaid($order, (string) ($intent['id'] ?? ''), (int) ($intent['amount_received'] ?? 0));
        } elseif ($event->type === 'payment_intent.payment_failed' && $order->payment_status === PaymentStatus::Unpaid) {
            // The customer can try another card on the same PaymentIntent.
            $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
        }

        $event->forceFill(['processed_at' => now()])->save();
    }

    /**
     * @param  array{id?: string, metadata?: array{order_public_id?: string}}  $intent
     */
    private function orderFor(array $intent): ?Order
    {
        if (isset($intent['id'])) {
            $order = Order::query()->where('stripe_payment_intent_id', $intent['id'])->first();

            if ($order !== null) {
                return $order;
            }
        }

        $publicId = $intent['metadata']['order_public_id'] ?? null;

        return $publicId === null ? null : Order::query()->where('public_id', $publicId)->first();
    }
}
