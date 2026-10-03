<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\SquareGateway;
use Illuminate\Support\Facades\Cache;

/**
 * Pays a Square order with the card or wallet token the app's Square form made: Square
 * charges it while the customer waits, and a completed payment places the order at once.
 *
 * Only one payment for an order goes through at a time (a lock the expiry of unpaid orders
 * respects too), an order is never charged once it's paid or cancelled, and each request's
 * Idempotency-Key makes Square charge a retried request once. A new card is a new request.
 */
final class SquareCheckoutService
{
    public function __construct(
        private readonly SquareGateway $square,
        private readonly OrderService $orders,
    ) {}

    /** The lock held while an order's payment goes through. */
    public static function lockName(Order $order): string
    {
        return "square-payment:{$order->id}";
    }

    /**
     * @return array{order: Order, completed: bool} completed is false while Square is still
     *                                              finishing the payment (its webhook then
     *                                              places the order)
     *
     * @throws CheckoutException
     * @throws PaymentDeclined
     * @throws PaymentsUnavailable
     */
    public function pay(Order $order, string $sourceId, ?string $verificationToken, string $idempotencyKey): array
    {
        if ($order->payment_processor !== PaymentProcessor::Square) {
            throw CheckoutException::notSquare();
        }

        // Long enough for Square's own timeout and retries.
        $lock = Cache::lock(self::lockName($order), 120);

        if (! $lock->get()) {
            throw CheckoutException::paymentInProgress();
        }

        try {
            $order = $order->refresh()->loadMissing('restaurant');

            // A retry after the payment went through.
            if ($order->payment_status === PaymentStatus::Paid || $order->payment_status === PaymentStatus::Refunded) {
                return ['order' => $order, 'completed' => true];
            }

            if ($order->status !== OrderStatus::PendingPayment) {
                throw CheckoutException::expired();
            }

            try {
                $payment = $this->square->charge($order, $sourceId, $verificationToken, self::idempotencyKey($order, $idempotencyKey));
            } catch (PaymentDeclined $declined) {
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();

                throw $declined;
            }

            if ($payment->completed()) {
                return ['order' => $this->orders->markPaid($order, $payment->id, $payment->amountCents), 'completed' => true];
            }

            if ($payment->failed()) {
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();

                throw new PaymentDeclined(PaymentDeclined::GENERIC);
            }

            // Approved but not finished yet: Square's webhook places the order once it is.
            $order->forceFill(['square_payment_id' => $payment->id])->save();

            return ['order' => $order, 'completed' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * Square's key for the charge: the same for a retried request, different for each new
     * request (a new card). Square allows 45 characters.
     */
    private static function idempotencyKey(Order $order, string $requestKey): string
    {
        return substr(hash('sha256', "{$order->public_id}:{$requestKey}"), 0, 40);
    }
}
