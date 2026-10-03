<?php

namespace App\Payments\Square;

final readonly class SquarePayment
{
    public function __construct(
        public string $id,
        /** APPROVED, PENDING, COMPLETED, CANCELED or FAILED. */
        public string $status,
        public int $amountCents,
    ) {}

    public function completed(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /** It didn't go through, and won't. */
    public function failed(): bool
    {
        return $this->status === 'FAILED' || $this->status === 'CANCELED';
    }
}
