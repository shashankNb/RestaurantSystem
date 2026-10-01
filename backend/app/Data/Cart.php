<?php

namespace App\Data;

use App\Enums\FulfilmentType;
use Carbon\CarbonImmutable;

/**
 * What the customer wants, as the app sends it: item and option IDs, quantities and
 * choices. Never prices; the server works those out.
 */
final readonly class Cart
{
    /**
     * @param  list<CartItem>  $items
     */
    public function __construct(
        public FulfilmentType $fulfilmentType,
        public array $items,
        public ?string $postcode = null,
        /** Null means as soon as possible. */
        public ?CarbonImmutable $scheduledFor = null,
        public ?string $promoCode = null,
    ) {}

    /**
     * From a validated request (QuoteRequest and, in phase 3, the order request).
     *
     * @param  array{fulfilment_type: string, items: list<array{menu_item_id: int|string, quantity: int|string, modifier_option_ids?: list<int|string>|null, notes?: string|null}>, postcode?: string|null, scheduled_for?: string|null, promo_code?: string|null}  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            fulfilmentType: FulfilmentType::from($data['fulfilment_type']),
            items: array_map(fn (array $item): CartItem => new CartItem(
                menuItemId: (int) $item['menu_item_id'],
                quantity: (int) $item['quantity'],
                optionIds: array_map('intval', $item['modifier_option_ids'] ?? []),
                notes: isset($item['notes']) && trim($item['notes']) !== '' ? trim($item['notes']) : null,
            ), $data['items']),
            postcode: isset($data['postcode']) && trim($data['postcode']) !== '' ? trim($data['postcode']) : null,
            scheduledFor: isset($data['scheduled_for']) ? CarbonImmutable::parse($data['scheduled_for'])->utc() : null,
            promoCode: isset($data['promo_code']) && trim($data['promo_code']) !== '' ? trim($data['promo_code']) : null,
        );
    }
}
