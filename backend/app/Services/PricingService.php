<?php

namespace App\Services;

use App\Data\Cart;
use App\Data\CartItem;
use App\Data\Quote;
use App\Data\QuoteError;
use App\Data\QuoteLine;
use App\Enums\FulfilmentType;
use App\Enums\PromoCodeType;
use App\Models\DeliveryZone;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Prices a cart and checks it can be ordered. The server is the only source of truth for
 * prices: the cart carries IDs, quantities and choices, and every amount is worked out
 * here from the menu.
 *
 * - A line costs (item price + chosen option prices) × quantity. Lines with a problem
 *   (sold out, a missing required choice) are reported but left out of the totals.
 * - Delivery adds the zone's fee; the zone's minimum applies to the food subtotal.
 * - A promo code takes a percentage or a fixed amount off the food subtotal.
 * - total = subtotal − discount + delivery fee; GST is total ÷ 11, to the nearest cent.
 */
final class PricingService
{
    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly OpeningHoursService $hours,
    ) {}

    public function quote(Restaurant $restaurant, Cart $cart, CarbonImmutable $now): Quote
    {
        $items = $this->orderableItems($restaurant, $cart);
        $lines = [];

        foreach ($cart->items as $index => $cartItem) {
            $lines[] = $this->priceLine($index, $cartItem, $items->get($cartItem->menuItemId));
        }

        $errors = array_merge(...array_map(fn (QuoteLine $line): array => $line->errors, $lines));
        $subtotal = array_sum(array_map(fn (QuoteLine $line): int => $line->isValid() ? $line->lineTotalCents : 0, $lines));

        [$zone, $estimatedMinutes, $fulfilmentErrors] = $this->fulfilment($restaurant, $cart, $subtotal);
        array_push($errors, ...$fulfilmentErrors);
        array_push($errors, ...$this->timing($restaurant, $cart, $now, $estimatedMinutes));

        $promo = $cart->promoCode === null ? null : $this->findPromo($restaurant, $cart->promoCode);
        $promoError = $cart->promoCode === null ? null : $this->promoProblem($restaurant, $promo, $cart->promoCode, $subtotal, $now);
        $discount = 0;

        if ($promoError !== null) {
            $errors[] = $promoError;
            $promo = null;
        } elseif ($promo !== null) {
            $discount = $this->discount($promo, $subtotal);
        }

        $deliveryFee = $zone->fee_cents ?? 0;
        $total = max(0, $subtotal - $discount + $deliveryFee);

        return new Quote(
            cart: $cart,
            lines: $lines,
            subtotalCents: $subtotal,
            deliveryFeeCents: $deliveryFee,
            discountCents: $discount,
            totalCents: $total,
            gstCents: self::gst($total),
            promoCode: $promo,
            deliveryZone: $zone,
            estimatedMinutes: $estimatedMinutes,
            errors: $errors,
        );
    }

    /**
     * GST included in a GST-inclusive amount: one eleventh, to the nearest cent.
     */
    public static function gst(int $totalCents): int
    {
        return (int) round($totalCents / 11);
    }

    /**
     * The cart's menu items that are still on the menu (active, in an active category),
     * with their option groups.
     *
     * @return Collection<int, MenuItem>
     */
    private function orderableItems(Restaurant $restaurant, Cart $cart): Collection
    {
        $ids = array_values(array_unique(array_map(fn (CartItem $item): int => $item->menuItemId, $cart->items)));

        return $restaurant->menuItems()
            ->active()
            ->whereHas('category', fn (Builder $query) => $query->where('is_active', true))
            ->whereKey($ids)
            ->with('modifierGroups.options')
            ->get()
            ->keyBy('id');
    }

    private function priceLine(int $index, CartItem $cartItem, ?MenuItem $item): QuoteLine
    {
        $field = "items.{$index}";

        if ($item === null) {
            return new QuoteLine($cartItem, null, [], 0, 0, [
                new QuoteError('item_not_found', 'An item in your cart is no longer on the menu. Remove it to continue.', "{$field}.menu_item_id"),
            ]);
        }

        $errors = [];

        if (! $item->is_available) {
            $errors[] = new QuoteError('item_sold_out', "{$item->name} is sold out. Remove it to continue.", "{$field}.menu_item_id");
        }

        // Only options from this item's own groups count; anything else is refused.
        $offered = [];

        foreach ($item->modifierGroups as $group) {
            foreach ($group->options as $option) {
                $offered[$option->id] = $option->setRelation('group', $group);
            }
        }

        $chosen = [];
        $unknown = false;

        foreach (array_unique($cartItem->optionIds) as $optionId) {
            $option = $offered[$optionId] ?? null;

            if ($option === null) {
                $unknown = true;

                continue;
            }

            $chosen[] = $option;

            if (! $option->is_available) {
                $errors[] = new QuoteError('option_sold_out', "{$option->name} is sold out. Choose something else for {$item->name}.", "{$field}.modifier_option_ids");
            }
        }

        if ($unknown) {
            $errors[] = new QuoteError('option_not_found', "Some choices for {$item->name} are no longer available. Choose them again.", "{$field}.modifier_option_ids");
        }

        foreach ($item->modifierGroups as $group) {
            $count = count(array_filter($chosen, fn (ModifierOption $option): bool => $option->modifier_group_id === $group->id));
            $problem = $this->selectionProblem($group, $count, $item->name);

            if ($problem !== null) {
                $errors[] = new QuoteError($count < $group->min_select ? 'too_few_options' : 'too_many_options', $problem, "{$field}.modifier_option_ids");
            }
        }

        $unitPrice = max(0, $item->price_cents + array_sum(array_map(fn (ModifierOption $option): int => $option->price_delta_cents, $chosen)));

        return new QuoteLine($cartItem, $item, $chosen, $unitPrice, $unitPrice * $cartItem->quantity, $errors);
    }

    private function selectionProblem(ModifierGroup $group, int $count, string $itemName): ?string
    {
        if ($count < $group->min_select) {
            return $group->min_select === 1
                ? "Make a choice under “{$group->name}” for {$itemName}."
                : "Make at least {$group->min_select} choices under “{$group->name}” for {$itemName}.";
        }

        if ($count > $group->max_select) {
            return $group->max_select === 1
                ? "Choose only one under “{$group->name}” for {$itemName}."
                : "Choose no more than {$group->max_select} under “{$group->name}” for {$itemName}.";
        }

        return null;
    }

    /**
     * Pickup or delivery: whether it's offered, the delivery zone and its minimum, and how
     * long an ASAP order takes (preparation, or delivery).
     *
     * @return array{0: ?DeliveryZone, 1: int, 2: list<QuoteError>}
     */
    private function fulfilment(Restaurant $restaurant, Cart $cart, int $subtotal): array
    {
        if ($cart->fulfilmentType === FulfilmentType::Pickup) {
            $errors = $restaurant->pickup_enabled ? [] : [
                new QuoteError('fulfilment_unavailable', 'We’re not offering pickup at the moment. Choose delivery instead.', 'fulfilment_type'),
            ];

            return [null, $restaurant->default_prep_minutes, $errors];
        }

        $check = $this->delivery->check($restaurant, $cart->postcode ?? '');

        if (! $check->isDeliverable()) {
            $minutes = $this->delivery->longestEstimatedMinutes($restaurant) ?? $restaurant->default_prep_minutes;

            return [null, $minutes, [new QuoteError('not_deliverable', (string) $check->message, 'postcode')]];
        }

        $zone = $check->zone;
        $errors = [];

        if ($subtotal < $zone->min_order_cents) {
            $errors[] = new QuoteError(
                'below_minimum',
                'Delivery orders need at least '.Money::format($zone->min_order_cents).' of food. Add '
                    .Money::format($zone->min_order_cents - $subtotal).' more, or choose pickup.',
                'items',
            );
        }

        return [$zone, $zone->estimated_minutes, $errors];
    }

    /**
     * ASAP orders need the restaurant open and taking orders; scheduled orders need one of
     * the offered times.
     *
     * @return list<QuoteError>
     */
    private function timing(Restaurant $restaurant, Cart $cart, CarbonImmutable $now, int $leadMinutes): array
    {
        $choose = $cart->fulfilmentType === FulfilmentType::Delivery
            ? 'Choose a delivery time to order ahead.'
            : 'Choose a pickup time to order ahead.';

        if ($cart->scheduledFor !== null) {
            return $this->hours->isSlot($restaurant, $cart->scheduledFor, $now, $leadMinutes) ? [] : [
                new QuoteError('invalid_time', 'We can’t take an order for that time. Choose one of the times offered.', 'scheduled_for'),
            ];
        }

        $status = $this->hours->status($restaurant, $now);

        if (! $status->isOpen) {
            $message = $status->nextOpeningAt === null
                ? 'We’re closed right now and not taking orders.'
                : 'We’re closed right now and open again '.LocalTime::describe($status->nextOpeningAt, $restaurant->timezone, $now).". {$choose}";

            return [new QuoteError('closed', $message, 'scheduled_for')];
        }

        if (! $restaurant->is_accepting_orders) {
            return [new QuoteError('paused', "We’ve paused orders for now. {$choose}", 'scheduled_for')];
        }

        return [];
    }

    private function findPromo(Restaurant $restaurant, string $code): ?PromoCode
    {
        return $restaurant->promoCodes()->where('code', PromoCode::normalise($code))->first();
    }

    private function promoProblem(Restaurant $restaurant, ?PromoCode $promo, string $code, int $subtotal, CarbonImmutable $now): ?QuoteError
    {
        $code = PromoCode::normalise($code);
        $problem = match (true) {
            $promo === null, ! $promo->is_active => "We don’t recognise the code {$code}. Check the spelling, or remove it.",
            $promo->starts_at !== null && $now->lessThan($promo->starts_at) => "The code {$code} starts "
                .LocalTime::describe($promo->starts_at->toImmutable(), $restaurant->timezone, $now).'. Remove it to order now.',
            $promo->ends_at !== null && $now->greaterThanOrEqualTo($promo->ends_at) => "The code {$code} has expired. Remove it to continue.",
            $promo->max_uses !== null && $promo->uses_count >= $promo->max_uses => "The code {$code} has been used up. Remove it to continue.",
            $subtotal < $promo->min_order_cents => "The code {$code} needs an order of ".Money::format($promo->min_order_cents)
                .' or more. Add '.Money::format($promo->min_order_cents - $subtotal).' more to use it.',
            default => null,
        };

        return $problem === null ? null : new QuoteError('promo_invalid', $problem, 'promo_code');
    }

    private function discount(PromoCode $promo, int $subtotal): int
    {
        $discount = $promo->type === PromoCodeType::Percent
            ? (int) round($subtotal * $promo->value / 100)
            : $promo->value;

        return min($discount, $subtotal);
    }
}
