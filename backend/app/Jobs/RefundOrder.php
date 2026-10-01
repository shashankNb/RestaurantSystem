<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refunds an order's payment in full. Queued so a slow or failing Stripe call never
 * blocks the kitchen; retried with the same idempotency key, so it refunds at most once.
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

    public function handle(PaymentGateway $payments): void
    {
        $order = Order::query()->with('restaurant')->find($this->orderId);

        if ($order === null || $order->payment_status !== PaymentStatus::Paid || $order->stripe_payment_intent_id === null) {
            return;
        }

        $refundId = $payments->refund($order, "refund-{$order->public_id}");

        Order::query()
            ->whereKey($order->id)
            ->where('payment_status', PaymentStatus::Paid)
            ->update([
                'payment_status' => PaymentStatus::Refunded,
                'stripe_refund_id' => $refundId,
                'refunded_at' => now(),
            ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('An order could not be refunded; refund it in the Stripe dashboard.', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }
}
