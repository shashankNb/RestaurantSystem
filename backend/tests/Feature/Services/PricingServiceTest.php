<?php

use App\Data\Cart;
use App\Data\CartItem;
use App\Data\Quote;
use App\Data\QuoteError;
use App\Enums\FulfilmentType;
use App\Models\MenuItem;
use App\Models\ModifierOption;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Tests\Support\MomoMenu;

// Monday 5 October 2026, 6 pm in Melbourne: open (5 pm to 10 pm), UTC+11.
function openNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne');
}

function priced(MomoMenu $menu, Cart $cart, ?CarbonImmutable $now = null): Quote
{
    return app(PricingService::class)->quote($menu->restaurant->fresh(), $cart, $now ?? openNow());
}

/**
 * @return list<string>
 */
function errorCodes(Quote $quote): array
{
    return array_map(fn (QuoteError $error): string => $error->code, $quote->errors);
}

beforeEach(function () {
    $this->menu = MomoMenu::create();
});

describe('prices', function () {
    it('prices a line as item plus options, times quantity', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([
            $menu->steamedLine(2, [$menu->pork->id, $menu->tomatoAchar->id, $menu->sesameAchar->id, $menu->hot->id]),
        ]));

        // 17.90 + 1.00 pork + 1.00 + 1.00 sauces = 20.90, × 2.
        expect($quote->lines[0]->unitPriceCents)->toBe(2090)
            ->and($quote->lines[0]->lineTotalCents)->toBe(4180)
            ->and($quote->subtotalCents)->toBe(4180)
            ->and($quote->canPlaceOrder())->toBeTrue();
    });

    it('adds up a pickup order with no fees', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(), new CartItem($menu->lassi->id, 2)]));

        expect($quote->subtotalCents)->toBe(3290)
            ->and($quote->deliveryFeeCents)->toBe(0)
            ->and($quote->discountCents)->toBe(0)
            ->and($quote->totalCents)->toBe(3290)
            ->and($quote->gstCents)->toBe(299);
    });

    it('adds the delivery fee and charges GST on the whole total', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(2)], FulfilmentType::Delivery, '3006'));

        expect($quote->deliveryFeeCents)->toBe(600)
            ->and($quote->totalCents)->toBe(4180)
            ->and($quote->gstCents)->toBe(380)
            ->and($quote->deliveryZone?->is($menu->zone))->toBeTrue()
            ->and($quote->estimatedMinutes)->toBe(45);
    });

    it('rounds GST to the nearest cent', function (int $total, int $gst) {
        expect(PricingService::gst($total))->toBe($gst);
    })->with([
        [1100, 100],
        [1105, 100],
        [1106, 101],
        [4182, 380],
        [0, 0],
    ]);

    it('never goes below zero when options take money off', function () {
        $menu = $this->menu;
        $menu->tomatoAchar->update(['price_delta_cents' => -5000]);

        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->chicken->id, $menu->mild->id, $menu->tomatoAchar->id])]));

        expect($quote->lines[0]->unitPriceCents)->toBe(0)
            ->and($quote->totalCents)->toBe(0);
    });
});

describe('promo codes', function () {
    it('takes a percentage off the food, not the delivery fee', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(2)], FulfilmentType::Delivery, '3006', promoCode: 'momo10'));

        // 10% of 35.80 is 3.58; delivery stays 6.00.
        expect($quote->discountCents)->toBe(358)
            ->and($quote->totalCents)->toBe(3580 - 358 + 600)
            ->and($quote->promoCode?->code)->toBe('MOMO10');
    });

    it('rounds a percentage discount to the nearest cent', function () {
        $menu = $this->menu;
        $menu->lassi->update(['price_cents' => 3005]);

        // 10% of 30.05 is 3.005, rounded to 3.01.
        expect(priced($menu, $menu->cart([new CartItem($menu->lassi->id, 1)], promoCode: 'MOMO10'))->discountCents)->toBe(301);
    });

    it('takes a fixed amount off, but never more than the food costs', function () {
        $menu = $this->menu;
        $menu->lassi->update(['price_cents' => 400]);

        expect(priced($menu, $menu->cart([$menu->steamedLine()], promoCode: 'fiveoff'))->discountCents)->toBe(500)
            ->and(priced($menu, $menu->cart([new CartItem($menu->lassi->id, 1)], promoCode: 'FIVEOFF'))->discountCents)->toBe(400);
    });

    it('explains why a code can’t be used', function (Closure $setUp, string $code, string $message) {
        $menu = $this->menu;
        $setUp($menu);

        $quote = priced($menu, $menu->cart([$menu->steamedLine()], promoCode: $code));

        expect(errorCodes($quote))->toBe(['promo_invalid'])
            ->and($quote->errors[0]->field)->toBe('promo_code')
            ->and($quote->errors[0]->message)->toContain($message)
            ->and($quote->discountCents)->toBe(0)
            ->and($quote->promoCode)->toBeNull();
    })->with([
        'unknown' => [fn () => null, 'nope', 'We don’t recognise the code NOPE'],
        'switched off' => [fn (MomoMenu $menu) => $menu->fiveOff->update(['is_active' => false]), 'FIVEOFF', 'We don’t recognise the code FIVEOFF'],
        'not started' => [fn (MomoMenu $menu) => $menu->fiveOff->update(['starts_at' => openNow()->addDays(2)->utc()]), 'FIVEOFF', 'starts on Wednesday 7 October at 6 pm'],
        'expired' => [fn (MomoMenu $menu) => $menu->fiveOff->update(['ends_at' => openNow()->subMinute()->utc()]), 'FIVEOFF', 'has expired'],
        'used up' => [fn (MomoMenu $menu) => $menu->fiveOff->forceFill(['max_uses' => 3, 'uses_count' => 3])->save(), 'FIVEOFF', 'has been used up'],
        'below its minimum' => [fn () => null, 'MOMO10', 'needs an order of $30.00 or more. Add $12.10 more to use it.'],
    ]);

    it('ignores another restaurant’s codes', function () {
        $menu = $this->menu;
        $other = Restaurant::factory()->create();
        PromoCode::factory()->for($other)->create(['code' => 'THEIRS', 'is_active' => true, 'starts_at' => null, 'ends_at' => null]);

        expect(errorCodes(priced($menu, $menu->cart([$menu->steamedLine()], promoCode: 'THEIRS'))))->toBe(['promo_invalid']);
    });
});

describe('option rules', function () {
    it('requires a choice in each required group', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->chicken->id])]));

        expect(errorCodes($quote))->toBe(['too_few_options'])
            ->and($quote->errors[0]->message)->toBe('Make a choice under “Spice level” for Steamed momo.')
            ->and($quote->errors[0]->field)->toBe('items.0.modifier_option_ids');
    });

    it('refuses more choices than a group allows', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->chicken->id, $menu->pork->id, $menu->mild->id])]));

        expect(errorCodes($quote))->toBe(['too_many_options'])
            ->and($quote->errors[0]->message)->toBe('Choose only one under “Choose filling” for Steamed momo.');
    });

    it('refuses options that belong to another item or restaurant', function () {
        $menu = $this->menu;
        $theirs = ModifierOption::factory()->create();

        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->chicken->id, $menu->mild->id, $theirs->id])]));

        expect(errorCodes($quote))->toBe(['option_not_found'])
            ->and($quote->lines[0]->unitPriceCents)->toBe(1790);
    });

    it('refuses a sold-out option', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->chicken->id, $menu->mild->id, $menu->chilliOil->id])]));

        expect(errorCodes($quote))->toBe(['option_sold_out'])
            ->and($quote->errors[0]->message)->toBe('Chilli oil is sold out. Choose something else for Steamed momo.');
    });

    it('counts a repeated option once', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(1, [$menu->pork->id, $menu->pork->id, $menu->mild->id])]));

        expect($quote->canPlaceOrder())->toBeTrue()
            ->and($quote->lines[0]->unitPriceCents)->toBe(1890);
    });
});

describe('items', function () {
    it('refuses items that are sold out or no longer on the menu', function (Closure $item, string $code) {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([new CartItem($item($menu), 1)]));

        expect(errorCodes($quote))->toContain($code)
            ->and($quote->errors[0]->field)->toBe('items.0.menu_item_id');
    })->with([
        'sold out' => [fn (MomoMenu $menu) => $menu->kothey->id, 'item_sold_out'],
        'inactive' => [fn (MomoMenu $menu) => $menu->retired->id, 'item_not_found'],
        'in an inactive category' => [fn (MomoMenu $menu) => $menu->secret->id, 'item_not_found'],
        'unknown' => [fn () => 999999, 'item_not_found'],
        'another restaurant’s' => [fn () => MenuItem::factory()->create(['price_cents' => 1])->id, 'item_not_found'],
    ]);

    it('leaves lines with problems out of the totals', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(), new CartItem($menu->kothey->id, 3)]));

        expect($quote->subtotalCents)->toBe(1790)
            ->and($quote->lines[1]->lineTotalCents)->toBe(5670)
            ->and($quote->canPlaceOrder())->toBeFalse();
    });
});

describe('pickup and delivery', function () {
    it('delivers only inside a zone', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine(2)], FulfilmentType::Delivery, '3121'));

        expect(errorCodes($quote))->toBe(['not_deliverable'])
            ->and($quote->errors[0]->field)->toBe('postcode')
            ->and($quote->deliveryFeeCents)->toBe(0);
    });

    it('applies the zone’s minimum to the food, before any discount', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([new CartItem($menu->lassi->id, 2)], FulfilmentType::Delivery, '3000'));

        expect(errorCodes($quote))->toBe(['below_minimum'])
            ->and($quote->errors[0]->message)->toBe('Delivery orders need at least $25.00 of food. Add $10.00 more, or choose pickup.');

        // $25.40 of food meets the minimum even though FIVEOFF takes it below $25.
        $menu->lassi->update(['price_cents' => 1270]);
        expect(priced($menu, $menu->cart([new CartItem($menu->lassi->id, 2)], FulfilmentType::Delivery, '3000', promoCode: 'FIVEOFF'))->canPlaceOrder())->toBeTrue();
    });

    it('refuses a fulfilment type the restaurant has switched off', function (string $column, FulfilmentType $type, string $code) {
        $menu = $this->menu;
        $menu->restaurant->update([$column => false]);

        expect(errorCodes(priced($menu, $menu->cart([$menu->steamedLine(2)], $type, '3006'))))->toBe([$code]);
    })->with([
        'pickup' => ['pickup_enabled', FulfilmentType::Pickup, 'fulfilment_unavailable'],
        'delivery' => ['delivery_enabled', FulfilmentType::Delivery, 'not_deliverable'],
    ]);
});

describe('when', function () {
    it('refuses ASAP orders while closed, and says when it opens', function () {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine()]), CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne'));

        expect(errorCodes($quote))->toBe(['closed'])
            ->and($quote->errors[0]->message)->toBe('We’re closed right now and open again today at 5 pm. Choose a pickup time to order ahead.');
    });

    it('refuses ASAP orders while ordering is paused', function () {
        $menu = $this->menu;
        $menu->restaurant->update(['is_accepting_orders' => false]);

        expect(errorCodes(priced($menu, $menu->cart([$menu->steamedLine()]))))->toBe(['paused']);
    });

    it('accepts one of the offered times, even while paused', function () {
        $menu = $this->menu;
        $menu->restaurant->update(['is_accepting_orders' => false]);
        $tomorrow = CarbonImmutable::parse('2026-10-06 18:30', 'Australia/Melbourne');

        expect(priced($menu, $menu->cart([$menu->steamedLine()], scheduledFor: $tomorrow))->canPlaceOrder())->toBeTrue();
    });

    it('refuses times that aren’t offered', function (string $localTime) {
        $menu = $this->menu;
        $quote = priced($menu, $menu->cart([$menu->steamedLine()], scheduledFor: CarbonImmutable::parse($localTime, 'Australia/Melbourne')));

        expect(errorCodes($quote))->toBe(['invalid_time']);
    })->with([
        'too soon for the kitchen' => ['2026-10-05 18:15'],
        'not on a quarter-hour' => ['2026-10-05 19:10'],
        'after closing' => ['2026-10-05 22:00'],
        'before opening' => ['2026-10-06 16:45'],
        'more than a week ahead' => ['2026-10-12 18:15'],
        'in the past' => ['2026-10-04 18:00'],
    ]);
});
