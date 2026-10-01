<?php

use Carbon\CarbonImmutable;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->base = "/api/v1/restaurants/{$this->menu->restaurant->slug}";

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

describe('delivery check', function () {
    it('returns the zone for a covered postcode', function () {
        $this->postJson("{$this->base}/delivery-check", ['postcode' => '3006'])
            ->assertOk()
            ->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.zone.fee_cents', 600)
            ->assertJsonPath('data.zone.min_order_cents', 2500)
            ->assertJsonPath('data.zone.estimated_minutes', 45)
            ->assertJsonPath('data.message', null);
    });

    it('explains when it can’t deliver', function () {
        $this->postJson("{$this->base}/delivery-check", ['postcode' => '3121'])
            ->assertOk()
            ->assertJsonPath('data.deliverable', false)
            ->assertJsonPath('data.zone', null)
            ->assertJsonPath('data.message', 'We don’t deliver to 3121. We deliver to 3000, 3006 and 3008. Choose pickup, or use an address in one of those postcodes.');
    });

    it('asks for a 4-digit postcode', function (mixed $postcode) {
        $this->postJson("{$this->base}/delivery-check", ['postcode' => $postcode])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postcode');
    })->with(['', '300', '30000', 'abcd', null]);

    it('slows down repeated checks', function () {
        foreach (range(1, 30) as $attempt) {
            $this->postJson("{$this->base}/delivery-check", ['postcode' => '3006'])->assertOk();
        }

        $this->postJson("{$this->base}/delivery-check", ['postcode' => '3006'])->assertTooManyRequests();
    });
});

describe('slots', function () {
    it('offers pickup now and every quarter-hour after the prep time', function () {
        $response = $this->getJson("{$this->base}/slots?fulfilment_type=pickup")->assertOk();

        expect($response->json('data.asap'))->toBe(['available' => true, 'estimated_minutes' => 20])
            // 6 pm + 20 minutes: the first slot is 6:30 pm (07:30 UTC).
            ->and($response->json('data.slots.0'))->toBe('2026-10-05T07:30:00Z');
    });

    it('allows for the delivery time', function () {
        $response = $this->getJson("{$this->base}/slots?fulfilment_type=delivery&postcode=3006")->assertOk();

        expect($response->json('data.asap.estimated_minutes'))->toBe(45)
            // 6 pm + 45 minutes: the first slot is 6:45 pm.
            ->and($response->json('data.slots.0'))->toBe('2026-10-05T07:45:00Z');
    });

    it('offers no ASAP orders while paused, but still offers later times', function () {
        $this->menu->restaurant->update(['is_accepting_orders' => false]);

        $response = $this->getJson("{$this->base}/slots?fulfilment_type=pickup");

        expect($response->json('data.asap.available'))->toBeFalse()
            ->and($response->json('data.slots'))->not->toBeEmpty();
    });

    it('offers nothing for a fulfilment type that is switched off', function () {
        $this->menu->restaurant->update(['delivery_enabled' => false]);

        $this->getJson("{$this->base}/slots?fulfilment_type=delivery")
            ->assertJsonPath('data.asap.available', false)
            ->assertJsonPath('data.slots', []);
    });

    it('requires a fulfilment type', function () {
        $this->getJson("{$this->base}/slots")->assertUnprocessable()->assertJsonValidationErrors('fulfilment_type');
    });
});
