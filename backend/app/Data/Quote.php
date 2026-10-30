<?php

namespace App\Data;

use App\Enums\Courier;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\PromoCode;

/**
 * A priced cart: the server's totals and everything that stops it being ordered as it is.
 * Money is integer cents, GST included.
 */
final readonly class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<QuoteError>  $errors  every problem, including each line's
     */
    public function __construct(
        public Cart $cart,
        public array $lines,
        public int $subtotalCents,
        public int $deliveryFeeCents,
        public int $discountCents,
        public int $totalCents,
        public int $gstCents,
        public ?PromoCode $promoCode,
        public ?DeliveryZone $deliveryZone,
        /** How long until the food is ready (pickup, dine in) or delivered, for ASAP orders. */
        public int $estimatedMinutes,
        public array $errors,
        /** Dine in: the table, when it's one of the restaurant's. */
        public ?DiningTable $table = null,
        /** Delivery: the courier that delivers it, or null for the restaurant's own drivers. */
        public ?Courier $courier = null,
        /**
         * The delivery fee is DoorDash's price for the address, which this cart didn't have yet:
         * it's added at checkout, and isn't in these totals.
         */
        public bool $deliveryFeePending = false,
    ) {}

    public function canPlaceOrder(): bool
    {
        return $this->errors === [];
    }
}
