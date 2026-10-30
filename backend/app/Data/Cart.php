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
        /** Dine in: the table's label, e.g. "12". */
        public ?string $table = null,
        /** Delivery: the address, once checkout has it (the cart itself knows only the postcode). */
        public ?DeliveryAddress $address = null,
    ) {}

    /**
     * From a validated request (QuoteRequest, or the order request).
     *
     * @param  array{fulfilment_type: string, items: list<array{menu_item_id: int|string, quantity: int|string, modifier_option_ids?: list<int|string>|null, notes?: string|null}>, postcode?: string|null, scheduled_for?: string|null, promo_code?: string|null, table?: string|null, delivery?: array{line1?: string|null, line2?: string|null, suburb?: string|null, state?: string|null}|null}  $data
     */
    public static function fromValidated(array $data): self
    {
        $text = fn (?string $value): ?string => $value !== null && trim($value) !== '' ? trim($value) : null;
        $type = FulfilmentType::from($data['fulfilment_type']);
        $postcode = $text($data['postcode'] ?? null);
        $delivery = $data['delivery'] ?? null;

        return new self(
            fulfilmentType: $type,
            items: array_map(fn (array $item): CartItem => new CartItem(
                menuItemId: (int) $item['menu_item_id'],
                quantity: (int) $item['quantity'],
                optionIds: array_map('intval', $item['modifier_option_ids'] ?? []),
                notes: $text($item['notes'] ?? null),
            ), $data['items']),
            postcode: $postcode,
            scheduledFor: isset($data['scheduled_for']) ? CarbonImmutable::parse($data['scheduled_for'])->utc() : null,
            promoCode: $text($data['promo_code'] ?? null),
            table: $text($data['table'] ?? null),
            address: $type === FulfilmentType::Delivery && is_array($delivery) ? new DeliveryAddress(
                line1: $text($delivery['line1'] ?? null),
                line2: $text($delivery['line2'] ?? null),
                suburb: $text($delivery['suburb'] ?? null),
                state: $text($delivery['state'] ?? null),
                postcode: $postcode,
            ) : null,
        );
    }
}
