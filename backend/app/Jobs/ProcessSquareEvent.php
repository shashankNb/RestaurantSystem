<?php

namespace App\Jobs;

use App\Enums\PaymentProcessor;
use App\Enums\SquareEnvironment;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\SquareEvent;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a verified, stored Square event. Payments are normally confirmed while the
 * customer waits (see SquareCheckoutService); these events catch the rest: a payment whose
 * answer never reached us, a refund Square couldn't make, an account disconnected on
 * Square's side. Each event is processed once.
 */
class ProcessSquareEvent implements ShouldQueue
{
    use Queueable;

    /** The events the platform acts on; others are acknowledged and ignored. */
    public const HANDLED = ['payment.created', 'payment.updated', 'refund.updated', 'oauth.authorization.revoked'];

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public readonly int $squareEventId) {}

    /**
     * Whether an event is about one of the platform's orders or connections. A restaurant's
     * Square account also takes its in-person payments; those events are acknowledged
     * without being stored.
     *
     * @param  array<string, mixed>  $event
     */
    public static function concernsUs(array $event, SquareEnvironment $environment): bool
    {
        $type = (string) ($event['type'] ?? '');
        $merchantId = $event['merchant_id'] ?? null;

        if (! in_array($type, self::HANDLED, true) || ! is_string($merchantId)) {
            return false;
        }

        if ($type === 'oauth.authorization.revoked') {
            return self::restaurants($merchantId, $environment)->exists();
        }

        $object = $event['data']['object'] ?? [];

        if ($type === 'refund.updated') {
            $refund = is_array($object) ? ($object['refund'] ?? []) : [];

            return is_array($refund) && isset($refund['id'])
                && self::orders($merchantId, $environment)->where('square_refund_id', $refund['id'])->exists();
        }

        $payment = is_array($object) ? ($object['payment'] ?? []) : [];

        return is_array($payment) && ($payment['status'] ?? null) === 'COMPLETED' && self::orderFor($payment, $merchantId, $environment) !== null;
    }

    public function handle(OrderService $orders): void
    {
        $event = SquareEvent::query()->find($this->squareEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $merchantId = (string) $event->merchant_id;
        $object = $event->payload['data']['object'] ?? [];

        if ($event->type === 'oauth.authorization.revoked') {
            self::restaurants($merchantId, $event->environment)->each(function (Restaurant $restaurant): void {
                Log::warning('A restaurant’s Square account was disconnected on Square’s side.', ['restaurant' => $restaurant->slug]);
                $restaurant->disconnectSquare();
            });
        } elseif ($event->type === 'refund.updated') {
            $refund = is_array($object) ? ($object['refund'] ?? []) : [];

            if (is_array($refund) && in_array($refund['status'] ?? null, ['FAILED', 'REJECTED'], true)) {
                Log::critical('Square couldn’t make a refund; refund it in the restaurant’s Square dashboard.', [
                    'refund' => $refund['id'] ?? null,
                    'payment' => $refund['payment_id'] ?? null,
                    'status' => $refund['status'],
                ]);
            }
        } else {
            $payment = is_array($object) ? ($object['payment'] ?? []) : [];

            if (is_array($payment) && ($payment['status'] ?? null) === 'COMPLETED') {
                $order = self::orderFor($payment, $merchantId, $event->environment);

                if ($order !== null) {
                    $orders->markPaid($order, (string) ($payment['id'] ?? ''), (int) ($payment['amount_money']['amount'] ?? 0));
                }
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }

    /**
     * The Square order a payment belongs to: by its payment ID, or by the order it names
     * (reference_id), among the orders of the restaurants connected to that Square account.
     *
     * @param  array<string, mixed>  $payment
     */
    private static function orderFor(array $payment, string $merchantId, SquareEnvironment $environment): ?Order
    {
        $id = $payment['id'] ?? null;
        $reference = $payment['reference_id'] ?? null;

        if (! is_string($id) && ! is_string($reference)) {
            return null;
        }

        return self::orders($merchantId, $environment)
            ->where(fn (Builder $query) => $query
                ->when(is_string($id), fn (Builder $query) => $query->orWhere('square_payment_id', $id))
                ->when(is_string($reference), fn (Builder $query) => $query->orWhere('public_id', $reference)))
            ->first();
    }

    /**
     * @return Builder<Order>
     */
    private static function orders(string $merchantId, SquareEnvironment $environment): Builder
    {
        return Order::query()
            ->where('payment_processor', PaymentProcessor::Square)
            ->whereIn('restaurant_id', self::restaurants($merchantId, $environment)->select('id'));
    }

    /**
     * @return Builder<Restaurant>
     */
    private static function restaurants(string $merchantId, SquareEnvironment $environment): Builder
    {
        return Restaurant::query()->where('square_merchant_id', $merchantId)->where('square_environment', $environment);
    }
}
