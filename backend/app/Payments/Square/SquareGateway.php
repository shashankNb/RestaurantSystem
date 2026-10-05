<?php

namespace App\Payments\Square;

use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\WalletSetup;

/**
 * Square, behind an interface so tests can swap in a fake. Every call acts for one
 * restaurant, with its own Square application's access token (and in its environment, from
 * its application ID); every call that changes something takes an idempotency key, so a
 * retry never charges or refunds twice.
 */
interface SquareGateway
{
    /**
     * The Square account's business name, for the back office.
     *
     * @throws SquareConnectionLost when Square doesn't accept the access token
     * @throws PaymentsUnavailable
     */
    public function merchantName(Restaurant $restaurant): string;

    /**
     * @return list<SquareLocation>
     *
     * @throws PaymentsUnavailable
     */
    public function locations(Restaurant $restaurant): array;

    /**
     * Registers the restaurant's website with Square for Apple Pay (Google Pay needs nothing),
     * and says whether Apple accepted it.
     *
     * @throws PaymentsUnavailable
     */
    public function registerApplePayDomain(Restaurant $restaurant, ?string $domain): WalletSetup;

    /**
     * Charges the order's total to a card or wallet token from Square's payment form, at the
     * restaurant's location. Repeating the key returns the same payment.
     *
     * @throws PaymentDeclined
     * @throws PaymentsUnavailable
     */
    public function charge(Order $order, string $sourceId, ?string $verificationToken, string $idempotencyKey): SquarePayment;

    /**
     * The order's Square payment (square_payment_id) as Square has it now: for an order whose
     * payment answer, or webhook, went astray.
     *
     * @throws PaymentsUnavailable
     */
    public function payment(Order $order): SquarePayment;

    /**
     * Refunds the order's payment in full and returns the refund's ID.
     *
     * @throws PaymentsUnavailable
     */
    public function refund(Order $order, string $idempotencyKey): string;
}
