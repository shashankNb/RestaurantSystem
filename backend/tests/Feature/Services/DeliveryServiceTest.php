<?php

use App\Models\DeliveryZone;
use App\Models\Restaurant;
use App\Services\DeliveryService;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create(['delivery_enabled' => true]);
    $this->inner = DeliveryZone::factory()->for($this->restaurant)->create([
        'name' => 'Inner Melbourne', 'postcodes' => ['3000', '3006', '3008'], 'fee_cents' => 600,
    ]);
    $this->delivery = app(DeliveryService::class);
});

it('finds the zone that covers a postcode', function () {
    $check = $this->delivery->check($this->restaurant, '3006');

    expect($check->isDeliverable())->toBeTrue()
        ->and($check->zone?->is($this->inner))->toBeTrue()
        ->and($check->message)->toBeNull();
});

it('ignores spaces around the postcode', function () {
    expect($this->delivery->check($this->restaurant, ' 30 06 ')->isDeliverable())->toBeTrue();
});

it('says which postcodes it delivers to when it can’t deliver', function () {
    DeliveryZone::factory()->for($this->restaurant)->create(['postcodes' => ['3051', '3000']]);

    $check = $this->delivery->check($this->restaurant, '3121');

    expect($check->isDeliverable())->toBeFalse()
        ->and($check->message)->toBe('We don’t deliver to 3121. We deliver to 3000, 3006, 3008 and 3051. Choose pickup, or use an address in one of those postcodes.');
});

it('ignores zones that are switched off', function () {
    DeliveryZone::factory()->for($this->restaurant)->inactive()->create(['postcodes' => ['3121']]);

    expect($this->delivery->check($this->restaurant, '3121')->isDeliverable())->toBeFalse();
});

it('picks the cheapest zone when zones overlap', function () {
    $cheaper = DeliveryZone::factory()->for($this->restaurant)->create(['postcodes' => ['3006'], 'fee_cents' => 400]);

    expect($this->delivery->zoneFor($this->restaurant, '3006')?->is($cheaper))->toBeTrue()
        ->and($this->delivery->zoneFor($this->restaurant, '3000')?->is($this->inner))->toBeTrue();
});

it('offers no delivery when the restaurant has switched it off', function () {
    $this->restaurant->update(['delivery_enabled' => false]);

    $check = $this->delivery->check($this->restaurant, '3006');

    expect($check->isDeliverable())->toBeFalse()
        ->and($check->message)->toBe('We’re not delivering at the moment. Choose pickup instead.');
});

it('never uses another restaurant’s zones', function () {
    DeliveryZone::factory()->for(Restaurant::factory())->create(['postcodes' => ['3121']]);

    expect($this->delivery->check($this->restaurant, '3121')->isDeliverable())->toBeFalse();
});

it('knows the longest delivery time of its active zones', function () {
    DeliveryZone::factory()->for($this->restaurant)->create(['estimated_minutes' => 60]);
    DeliveryZone::factory()->for($this->restaurant)->inactive()->create(['estimated_minutes' => 90]);

    expect($this->delivery->longestEstimatedMinutes($this->restaurant))->toBe(60)
        ->and($this->delivery->longestEstimatedMinutes(Restaurant::factory()->create()))->toBeNull();
});
