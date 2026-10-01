<?php

namespace App\Data;

final readonly class CartItem
{
    /**
     * @param  list<int>  $optionIds
     */
    public function __construct(
        public int $menuItemId,
        public int $quantity,
        public array $optionIds = [],
        public ?string $notes = null,
    ) {}
}
