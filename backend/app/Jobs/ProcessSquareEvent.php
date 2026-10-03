<?php

namespace App\Jobs;

use App\Enums\PaymentProcessor;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\SquareEvent;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a verified, stored Square event from a restaurant's own Square application.
 * Payments are normally confirmed while the customer waits (see SquareCheckoutService);
 * these events catch the rest: a payment whose answer never reached us, and a refund Square
 * couldn't make. Each event is processed once, and only ever touches its restaurant's orders.
 */
class ProcessSquareEvent implements ShouldQueue
{
    use Queueable;

    /** The events the platform acts on; others are acknowledged and ignored. */
    public const HANDLED = ['payment.created', 'payment.updated', 'refund.updated'];

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public readonly int $squareEventId) {}

    /**
     * Whether an event is about one of the restaurant's online orders. Its Square account also
     * takes its in-person payments; those events are acknowledged without being stored.
     *
     * @param  array<string, mixed>  $event
     */
    public static function concernsUs(array $event, Restaurant $restaurant): bool
    {
        $type = (string) ($event['type'] ?? '');

        if (! in_array($type, self::HANDLED, true)) {
            return false;
        }

        $object = $event['data']['object'] ?? [];

        if ($type === 'refund.updated') {
            $refund = is_array($object) ? ($object['refund'] ?? []) : [];

            return is_array($refund) && isset($refund['id'])
                && self::orders($restaurant)->where('square_refund_id', $refund['id'])->exists();
        }

        $payment = is_array($object) ? ($object['payment'] ?? []) : [];

        return is_array($payment) && ($payment['status'] ?? null) === 'COMPLETED' && self::orderFor($payment, $restaurant) !== null;
    }

    public function handle(OrderService $orders): void
    {
        $event = SquareEvent::query()->with('restaurant')->find($this->squareEventId);

        if ($event === null || $event->processed_at !== null || $event->restaurant === null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];

        if ($event->type === 'refund.updated') {
            $refund = is_array($object) ? ($object['refund'] ?? []) : [];

            if (is_array($refund) && in_array($refund['status'] ?? null, ['FAILED', 'REJECTED'], true)) {
                Log::critical('Square couldn’t make a refund; refund it in the restaurant’s Square dashboard.', [
                    'restaurant' => $event->restaurant->slug,
                    'refund' => $refund['id'] ?? null,
                    'payment' => $refund['payment_id'] ?? null,
                    'status' => $refund['status'],
                ]);
            }
        } else {
            $payment = is_array($object) ? ($object['payment'] ?? []) : [];

            if (is_array($payment) && ($payment['status'] ?? null) === 'COMPLETED') {
                $order = self::orderFor($payment, $event->restaurant);

                if ($order !== null) {
                    $orders->markPaid($order, (string) ($payment['id'] ?? ''), (int) ($payment['amount_money']['amount'] ?? 0));
                }
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }

    /**
     * The restaurant's Square order a payment belongs to: by its payment ID, or by the order
     * it names (reference_id).
     *
     * @param  array<string, mixed>  $payment
     */
    private static function orderFor(array $payment, Restaurant $restaurant): ?Order
    {
        $id = $payment['id'] ?? null;
        $reference = $payment['reference_id'] ?? null;

        if (! is_string($id) && ! is_string($reference)) {
            return null;
        }

        return self::orders($restaurant)
            ->where(fn (Builder $query) => $query
                ->when(is_string($id), fn (Builder $query) => $query->orWhere('square_payment_id', $id))
                ->when(is_string($reference), fn (Builder $query) => $query->orWhere('public_id', $reference)))
            ->first();
    }

    /**
     * @return Builder<Order>
     */
    private static function orders(Restaurant $restaurant): Builder
    {
        return Order::query()->where('restaurant_id', $restaurant->id)->where('payment_processor', PaymentProcessor::Square);
    }
}
