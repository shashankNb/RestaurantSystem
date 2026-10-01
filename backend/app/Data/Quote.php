<?php

namespace App\Data;

use App\Models\DeliveryZone;
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
        /** How long until the food is ready (pickup) or delivered, for ASAP orders. */
        public int $estimatedMinutes,
        public array $errors,
    ) {}

    public function canPlaceOrder(): bool
    {
        return $this->errors === [];
    }
}
