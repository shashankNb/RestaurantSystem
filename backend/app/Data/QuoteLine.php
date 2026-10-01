<?php

namespace App\Data;

use App\Models\MenuItem;
use App\Models\ModifierOption;

/**
 * One cart line, priced by the server. A line with errors (a sold-out item, a missing
 * required choice) is shown to the customer but left out of the totals.
 */
final readonly class QuoteLine
{
    /**
     * @param  list<ModifierOption>  $options  the chosen options that exist for this item
     * @param  list<QuoteError>  $errors
     */
    public function __construct(
        public CartItem $cartItem,
        public ?MenuItem $item,
        public array $options,
        public int $unitPriceCents,
        public int $lineTotalCents,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
