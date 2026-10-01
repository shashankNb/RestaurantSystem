<?php

use Carbon\CarbonImmutable;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->url = "/api/v1/restaurants/{$this->menu->restaurant->slug}/orders/quote";

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

/**
 * @return array<string, mixed>
 */
function deliveryCart(MomoMenu $menu, array $overrides = []): array
{
    return array_replace([
        'fulfilment_type' => 'delivery',
        'postcode' => '3006',
        'promo_code' => 'momo10',
        'items' => [
            ['menu_item_id' => $menu->steamed->id, 'quantity' => 2, 'modifier_option_ids' => [$menu->pork->id, $menu->tomatoAchar->id, $menu->hot->id], 'notes' => 'Extra achar'],
        ],
    ], $overrides);
}

it('returns the server’s prices for a cart', function () {
    $this->postJson($this->url, deliveryCart($this->menu))
        ->assertOk()
        ->assertJsonPath('data.can_place_order', true)
        ->assertJsonPath('data.lines.0.name', 'Steamed momo')
        ->assertJsonPath('data.lines.0.unit_price_cents', 1990)
        ->assertJsonPath('data.lines.0.line_total_cents', 3980)
        ->assertJsonPath('data.lines.0.notes', 'Extra achar')
        ->assertJsonPath('data.lines.0.modifiers.0', ['id' => $this->menu->pork->id, 'group' => 'Choose filling', 'name' => 'Pork', 'price_delta_cents' => 100])
        ->assertJsonPath('data.subtotal_cents', 3980)
        ->assertJsonPath('data.delivery_fee_cents', 600)
        ->assertJsonPath('data.discount_cents', 398)
        ->assertJsonPath('data.total_cents', 4182)
        ->assertJsonPath('data.gst_cents', 380)
        ->assertJsonPath('data.promo_code', ['code' => 'MOMO10', 'description' => '10% off'])
        ->assertJsonPath('data.fulfilment.type', 'delivery')
        ->assertJsonPath('data.fulfilment.estimated_minutes', 45)
        ->assertJsonPath('data.fulfilment.delivery_zone.name', 'Inner Melbourne')
        ->assertJsonPath('data.scheduled_for', null)
        ->assertJsonPath('data.errors', []);
});

it('ignores prices and totals sent by the app', function () {
    $tampered = deliveryCart($this->menu, [
        'total_cents' => 1,
        'subtotal_cents' => 1,
        'discount_cents' => 999999,
        'delivery_fee_cents' => 0,
    ]);
    $tampered['items'][0] += ['unit_price_cents' => 1, 'price_cents' => 1, 'line_total_cents' => 1];

    $this->postJson($this->url, $tampered)
        ->assertOk()
        ->assertJsonPath('data.total_cents', 4182)
        ->assertJsonPath('data.lines.0.unit_price_cents', 1990);
});

it('reports what stops a cart being ordered, with the totals', function () {
    $response = $this->postJson($this->url, deliveryCart($this->menu, [
        'postcode' => '3121',
        'items' => [
            ['menu_item_id' => $this->menu->steamed->id, 'quantity' => 1, 'modifier_option_ids' => [$this->menu->chicken->id]],
            ['menu_item_id' => $this->menu->lassi->id, 'quantity' => 1],
        ],
    ]))->assertOk();

    expect($response->json('data.can_place_order'))->toBeFalse()
        ->and($response->json('data.lines.0.errors'))->toBe(['Make a choice under “Spice level” for Steamed momo.'])
        ->and($response->json('data.subtotal_cents'))->toBe(750)
        ->and(array_column($response->json('data.errors'), 'code'))->toBe(['too_few_options', 'not_deliverable', 'promo_invalid'])
        ->and(array_column($response->json('data.errors'), 'field'))->toBe(['items.0.modifier_option_ids', 'postcode', 'promo_code']);
});

it('accepts a scheduled time and echoes it back', function () {
    $this->postJson($this->url, deliveryCart($this->menu, ['scheduled_for' => '2026-10-06T19:00:00+11:00']))
        ->assertOk()
        ->assertJsonPath('data.can_place_order', true)
        ->assertJsonPath('data.scheduled_for', '2026-10-06T08:00:00Z');
});

it('rejects a malformed cart with the standard validation error', function (array $overrides, string $field) {
    $this->postJson($this->url, deliveryCart($this->menu, $overrides))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => [$field]]);
})->with([
    'no items' => [['items' => []], 'items'],
    'no fulfilment type' => [['fulfilment_type' => 'teleport'], 'fulfilment_type'],
    'delivery without a postcode' => [['postcode' => null], 'postcode'],
    'a bad postcode' => [['postcode' => '30o6'], 'postcode'],
    'a silly quantity' => [['items' => [['menu_item_id' => 1, 'quantity' => 51]]], 'items.0.quantity'],
    'a zero quantity' => [['items' => [['menu_item_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
    'a time that isn’t a time' => [['scheduled_for' => 'dinner time'], 'scheduled_for'],
]);

it('lets two lines choose the same option', function () {
    $line = ['menu_item_id' => $this->menu->steamed->id, 'quantity' => 1, 'modifier_option_ids' => [$this->menu->chicken->id, $this->menu->mild->id]];

    $this->postJson($this->url, deliveryCart($this->menu, ['items' => [$line, $line]]))
        ->assertOk()
        ->assertJsonPath('data.subtotal_cents', 3580);
});

it('slows down rapid quotes, which also stops promo-code guessing', function () {
    foreach (range(1, 60) as $attempt) {
        $this->postJson($this->url, deliveryCart($this->menu, ['promo_code' => "GUESS{$attempt}"]))->assertOk();
    }

    $this->postJson($this->url, deliveryCart($this->menu))->assertTooManyRequests();
});

it('returns 404 for an unknown restaurant', function () {
    $this->postJson('/api/v1/restaurants/no-such-place/orders/quote', deliveryCart($this->menu))->assertNotFound();
});
