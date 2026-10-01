<?php

use App\Models\DeliveryZone;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create([
        'name' => 'Himalayan Momo House',
        'slug' => 'himalayan-momo-house',
        'brand_color' => '#7A1F2B',
        'default_prep_minutes' => 20,
    ]);

    foreach (range(0, 6) as $day) {
        $this->restaurant->openingHours()->create([
            'day_of_week' => $day,
            'opens_at' => '17:00:00',
            'closes_at' => in_array($day, [5, 6], true) ? '23:00:00' : '22:00:00',
        ]);
    }

    DeliveryZone::factory()->for($this->restaurant)->create(['name' => 'Inner Melbourne']);
    DeliveryZone::factory()->for($this->restaurant)->inactive()->create(['name' => 'Paused zone']);

    // Monday 5 October 2026, 6:30 pm in Melbourne (UTC+11).
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:30', 'Australia/Melbourne'));
});

it('returns the restaurant’s details, status and fulfilment options', function () {
    $this->getJson('/api/v1/restaurants/himalayan-momo-house')
        ->assertOk()
        ->assertJsonPath('data.slug', 'himalayan-momo-house')
        ->assertJsonPath('data.name', 'Himalayan Momo House')
        ->assertJsonPath('data.brand_color', '#7A1F2B')
        ->assertJsonPath('data.timezone', 'Australia/Melbourne')
        ->assertJsonPath('data.currency', 'AUD')
        ->assertJsonPath('data.status', [
            'is_open' => true,
            'is_accepting_orders' => true,
            'can_order_asap' => true,
            'closes_at' => '2026-10-05T11:00:00Z',
            'next_opening_at' => null,
        ])
        ->assertJsonPath('data.fulfilment.pickup', ['enabled' => true, 'prep_minutes' => 20])
        ->assertJsonPath('data.fulfilment.delivery.enabled', true)
        ->assertJsonPath('data.fulfilment.delivery.zones', [[
            'name' => 'Inner Melbourne',
            'postcodes' => ['3000', '3006', '3008'],
            'fee_cents' => 600,
            'min_order_cents' => 2500,
            'estimated_minutes' => 45,
        ]]);
});

it('lists opening hours Monday first, as local times', function () {
    $hours = $this->getJson('/api/v1/restaurants/himalayan-momo-house')->json('data.opening_hours');

    expect($hours)->toHaveCount(7)
        ->and($hours[0])->toBe(['day_of_week' => 1, 'opens_at' => '17:00', 'closes_at' => '22:00'])
        ->and($hours[4])->toBe(['day_of_week' => 5, 'opens_at' => '17:00', 'closes_at' => '23:00'])
        ->and($hours[6]['day_of_week'])->toBe(0);
});

it('lists special hours for the next 30 days only', function () {
    $this->restaurant->specialHours()->create(['date' => '2026-10-04', 'is_closed' => true, 'note' => 'Yesterday']);
    $this->restaurant->specialHours()->create(['date' => '2026-10-20', 'is_closed' => false, 'opens_at' => '12:00:00', 'closes_at' => '15:00:00', 'note' => 'Lunch only']);
    $this->restaurant->specialHours()->create(['date' => '2026-12-25', 'is_closed' => true, 'note' => 'Christmas Day']);

    $this->getJson('/api/v1/restaurants/himalayan-momo-house')
        ->assertJsonPath('data.special_hours', [[
            'date' => '2026-10-20',
            'is_closed' => false,
            'opens_at' => '12:00',
            'closes_at' => '15:00',
            'note' => 'Lunch only',
        ]]);
});

it('says when a closed restaurant opens next', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne'));

    $this->getJson('/api/v1/restaurants/himalayan-momo-house')
        ->assertJsonPath('data.status.is_open', false)
        ->assertJsonPath('data.status.can_order_asap', false)
        ->assertJsonPath('data.status.closes_at', null)
        ->assertJsonPath('data.status.next_opening_at', '2026-10-05T06:00:00Z');
});

it('does not offer ASAP orders while ordering is paused', function () {
    $this->restaurant->update(['is_accepting_orders' => false]);

    $this->getJson('/api/v1/restaurants/himalayan-momo-house')
        ->assertJsonPath('data.status.is_open', true)
        ->assertJsonPath('data.status.is_accepting_orders', false)
        ->assertJsonPath('data.status.can_order_asap', false);
});

it('turns delivery off when there is no active zone', function () {
    $this->restaurant->deliveryZones()->update(['is_active' => false]);

    $this->getJson('/api/v1/restaurants/himalayan-momo-house')
        ->assertJsonPath('data.fulfilment.delivery.enabled', false)
        ->assertJsonPath('data.fulfilment.delivery.zones', []);
});

it('never shows another restaurant’s zones or hours', function () {
    $other = Restaurant::factory()->create();
    DeliveryZone::factory()->for($other)->create(['name' => 'Somewhere else']);
    $other->openingHours()->create(['day_of_week' => 1, 'opens_at' => '06:00:00', 'closes_at' => '09:00:00']);

    $response = $this->getJson('/api/v1/restaurants/himalayan-momo-house');

    expect(collect($response->json('data.fulfilment.delivery.zones'))->pluck('name')->all())->toBe(['Inner Melbourne'])
        ->and(collect($response->json('data.opening_hours'))->pluck('opens_at')->unique()->all())->toBe(['17:00']);
});

it('returns a plain 404 for an unknown restaurant', function () {
    $this->getJson('/api/v1/restaurants/no-such-place')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('answers in JSON even without an Accept header', function () {
    $this->get('/api/v1/restaurants/no-such-place')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('allows the web app’s origin and no other', function () {
    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/restaurants/himalayan-momo-house', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    // With a single allowed origin, the header always names it, so browsers block every
    // other site: what matters is that a foreign origin is never echoed back.
    expect($preflight('http://localhost:8081')->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:8081')
        ->and($preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});
