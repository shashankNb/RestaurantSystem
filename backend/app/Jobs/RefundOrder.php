<?php

namespace App\Jobs;

use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Payments\Square\SquareGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refunds an order's payment in full, through the processor it was paid with (even if the
 * restaurant has switched since). Queued so a slow or failing payment provider never blocks
 * the kitchen; retried with the same idempotency key, so it refunds at most once.
 */
class RefundOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $orderId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(PaymentGateway $stripe, SquareGateway $square): void
    {
        $order = Order::query()->with('restaurant')->find($this->orderId);

        if ($order === null || $order->payment_status !== PaymentStatus::Paid) {
            return;
        }

        $refund = match ($order->payment_processor) {
            PaymentProcessor::Stripe => $order->stripe_payment_intent_id === null
                ? null
                : ['stripe_refund_id' => $stripe->refund($order, "refund-{$order->public_id}")],
            PaymentProcessor::Square => $order->square_payment_id === null
                ? null
                : ['square_refund_id' => $square->refund($order, "refund-{$order->public_id}")],
        };

        if ($refund === null) {
            return;
        }

        Order::query()
            ->whereKey($order->id)
            ->where('payment_status', PaymentStatus::Paid)
            ->update([
                'payment_status' => PaymentStatus::Refunded,
                ...$refund,
                'refunded_at' => now(),
            ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('An order could not be refunded; refund it in the restaurant’s Stripe or Square dashboard.', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }
}
