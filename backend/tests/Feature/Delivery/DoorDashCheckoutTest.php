<?php

use App\Delivery\DoorDash\DoorDashGateway;
use App\Enums\Courier;
use App\Enums\DoorDashFee;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDoorDashGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    setUpDoorDash($this->menu->restaurant);
    /** @var FakeDoorDashGateway $doordash */
    $doordash = app(DoorDashGateway::class);
    $this->doordash = $doordash;

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

/**
 * Two chicken momos ($35.80) for delivery to Southbank: the cart's quote (postcode only), or
 * checkout's (with the address).
 *
 * @param  array<string, mixed>  $overrides
 */
function deliveryQuote(MomoMenu $menu, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders/quote", $menu->deliveryPayload($overrides))->assertOk();
}

it('prices delivery from the postcode alone when customers pay the zone’s fee', function () {
    deliveryQuote($this->menu, ['delivery' => null])
        ->assertJsonPath('data.delivery_fee_cents', 600)
        ->assertJsonPath('data.total_cents', 3580 + 600)
        ->assertJsonPath('data.fulfilment.courier', 'doordash')
        ->assertJsonPath('data.fulfilment.delivery_fee_pending', false)
        ->assertJsonPath('data.can_place_order', true);

    expect($this->doordash->quotes)->toBe([]);
});

it('checks the customer’s address with DoorDash once checkout sends it', function () {
    deliveryQuote($this->menu)
        ->assertJsonPath('data.delivery_fee_cents', 600)
        ->assertJsonPath('data.can_place_order', true);

    $quote = $this->doordash->quotes[0];

    expect($quote)->toMatchArray([
        'pickup_business_name' => $this->menu->restaurant->name,
        'pickup_phone_number' => '+61355501234',
        'dropoff_address' => '12 Southbank Boulevard, Southbank VIC 3006, Australia',
        'order_value' => 3580,
    ])
        ->and($quote['pickup_address'])->toEndWith('Melbourne VIC 3000, Australia')
        ->and($quote['external_delivery_id'])->toStartWith('price-');
});

it('says when DoorDash can’t deliver to the address', function () {
    $this->doordash->refuseNextQuote = 'dropoff_address: Address is outside of the coverage area';

    deliveryQuote($this->menu)
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0', [
            'code' => 'not_deliverable',
            'message' => 'DoorDash can’t deliver to this address. Check the street address and suburb, or choose pickup.',
            'field' => 'delivery.line1',
        ]);
});

it('takes no delivery orders while DoorDash can’t be asked, since nobody would come', function () {
    $this->doordash->failNext = true;

    deliveryQuote($this->menu)
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0.code', 'delivery_unavailable')
        ->assertJsonPath('data.errors.0.field', 'delivery.line1');
});

it('charges DoorDash’s price for the address when the owner chooses it', function () {
    setUpDoorDash($this->menu->restaurant, DoorDashFee::Quote);

    // The cart knows only the postcode: the fee comes at checkout, and isn't in the total yet.
    deliveryQuote($this->menu, ['delivery' => null])
        ->assertJsonPath('data.delivery_fee_cents', 0)
        ->assertJsonPath('data.total_cents', 3580)
        ->assertJsonPath('data.fulfilment.delivery_fee_pending', true)
        ->assertJsonPath('data.can_place_order', true);

    deliveryQuote($this->menu)
        ->assertJsonPath('data.delivery_fee_cents', 975)
        ->assertJsonPath('data.total_cents', 3580 + 975)
        ->assertJsonPath('data.gst_cents', (int) round((3580 + 975) / 11))
        ->assertJsonPath('data.fulfilment.delivery_fee_pending', false);

    // The zone still sets the minimum order.
    deliveryQuote($this->menu, ['items' => [['menu_item_id' => $this->menu->lassi->id, 'quantity' => 1]]])
        ->assertJsonPath('data.errors.0.code', 'below_minimum');
});

it('keeps DoorDash’s price for a few minutes, so checkout and the order agree', function () {
    setUpDoorDash($this->menu->restaurant, DoorDashFee::Quote);

    deliveryQuote($this->menu);
    $this->doordash->feeCents = 1150;
    deliveryQuote($this->menu)->assertJsonPath('data.delivery_fee_cents', 975);

    expect($this->doordash->quotes)->toHaveCount(1);

    $this->travel(5)->minutes();
    deliveryQuote($this->menu)->assertJsonPath('data.delivery_fee_cents', 1150);
});

it('places the order with DoorDash delivering it, at DoorDash’s price', function () {
    setUpDoorDash($this->menu->restaurant, DoorDashFee::Quote);

    $this->withHeader('Idempotency-Key', 'doordash-order-0001')
        ->postJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/orders", $this->menu->deliveryPayload())
        ->assertCreated()
        ->assertJsonPath('data.order.courier', ['name' => 'DoorDash', 'status' => null, 'driver_name' => null, 'tracking_url' => null]);

    $order = Order::query()->sole();

    expect($order->courier)->toBe(Courier::DoorDash)
        ->and($order->delivery_fee_cents)->toBe(975)
        ->and($order->total_cents)->toBe(3580 + 975)
        // Nothing is booked until the kitchen accepts it.
        ->and($order->courier_delivery_id)->toBeNull()
        ->and($this->doordash->accepted)->toBe([]);
});

it('refuses an order DoorDash can’t deliver, before anything is paid', function () {
    $this->doordash->refuseNextQuote = 'dropoff_address: Address is outside of the coverage area';

    $this->withHeader('Idempotency-Key', 'doordash-order-0001')
        ->postJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/orders", $this->menu->deliveryPayload())
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['delivery.line1' => ['DoorDash can’t deliver to this address. Check the street address and suburb, or choose pickup.']]);

    expect(Order::query()->count())->toBe(0);
});

it('needs a phone number the driver can call', function () {
    $payload = $this->menu->deliveryPayload(['customer' => ['name' => 'Sam Taylor', 'phone' => '5550 1234', 'email' => 'sam@example.com']]);

    $this->withHeader('Idempotency-Key', 'doordash-order-0001')
        ->postJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/orders", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer.phone' => 'Enter a phone number the delivery driver can call, like 0412 345 678.']);
});

it('leaves pickup orders, and restaurants that deliver themselves, to the restaurant', function () {
    $this->withHeader('Idempotency-Key', 'pickup-order-0001')
        ->postJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/orders", $this->menu->orderPayload())
        ->assertCreated();

    $this->menu->restaurant->forceFill(['doordash_enabled' => false])->save();
    deliveryQuote($this->menu)->assertJsonPath('data.fulfilment.courier', null);

    expect(Order::query()->sole()->courier)->toBeNull()
        ->and($this->doordash->quotes)->toBe([]);
});

it('tells the apps who delivers, and whether the fee comes from the address', function () {
    $show = fn () => $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")->assertOk();

    $show()->assertJsonPath('data.fulfilment.delivery.courier', 'doordash')
        ->assertJsonPath('data.fulfilment.delivery.fee_from_address', false);

    setUpDoorDash($this->menu->restaurant, DoorDashFee::Quote);
    $show()->assertJsonPath('data.fulfilment.delivery.fee_from_address', true);

    // DoorDash needs a number for its drivers to call: without one, the restaurant delivers.
    $this->menu->restaurant->forceFill(['phone' => null])->save();
    $show()->assertJsonPath('data.fulfilment.delivery.courier', null)
        ->assertJsonPath('data.fulfilment.delivery.fee_from_address', false);
});
