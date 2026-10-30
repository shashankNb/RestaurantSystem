<?php

use App\Delivery\DoorDash\DoorDashGateway;
use App\Enums\CourierStatus;
use App\Enums\OrderStatus;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDoorDashGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    setUpDoorDash($this->menu->restaurant);
    $this->staff = User::factory()->staffOf($this->menu->restaurant)->create();
    /** @var FakeDoorDashGateway $doordash */
    $doordash = app(DoorDashGateway::class);
    $this->doordash = $doordash;

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));

    // Accepted by the kitchen, so its driver is booked.
    $this->order = app(OrderService::class)->accept(placeDeliveryOrder($this->menu), 20, $this->staff)->refresh();
});

/**
 * DoorDash's webhook for one of the restaurant's deliveries, with the restaurant's own
 * Authorization value unless another is given.
 *
 * @param  array<string, mixed>  $fields
 */
function doorDashEvent(Restaurant $restaurant, string $eventName, string $externalId, array $fields = [], ?string $authorization = 'ddwh_test_secret'): TestResponse
{
    return test()->withHeaders($authorization === null ? [] : ['Authorization' => $authorization])
        ->postJson("/api/v1/doordash/webhook/{$restaurant->slug}", [
            'event_name' => $eventName,
            'external_delivery_id' => $externalId,
            'created_at' => now()->utc()->toIso8601ZuluString(),
            ...$fields,
        ]);
}

it('refuses a webhook without the restaurant’s Authorization value', function (?string $authorization) {
    doorDashEvent($this->menu->restaurant, 'DASHER_PICKED_UP', $this->order->public_id, authorization: $authorization)
        ->assertUnauthorized();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Accepted)
        ->and($this->order->courier_status)->toBe(CourierStatus::Booked);
})->with([
    'none' => [null],
    'someone else’s' => ['ddwh_another_restaurant'],
    'empty' => [''],
]);

it('takes the value with or without a scheme in front', function (string $authorization) {
    doorDashEvent($this->menu->restaurant, 'DASHER_CONFIRMED', $this->order->public_id, ['dasher_name' => 'Priya S.'], $authorization)
        ->assertOk();

    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::DriverAssigned);
})->with(['Basic ddwh_test_secret', 'bearer ddwh_test_secret']);

it('follows the driver to the restaurant and on to the customer', function () {
    $restaurant = $this->menu->restaurant;
    $id = $this->order->public_id;

    doorDashEvent($restaurant, 'DASHER_CONFIRMED', $id, ['dasher_name' => 'Priya S.'])->assertOk();
    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::DriverAssigned)
        ->and($this->order->courier_driver_name)->toBe('Priya S.');

    doorDashEvent($restaurant, 'DASHER_CONFIRMED_PICKUP_ARRIVAL', $id)->assertOk();
    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::AtPickup);

    // The driver has it: out for delivery, through the steps the kitchen didn't tap.
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $id)->assertOk();
    $order = $this->order->refresh();

    expect($order->status)->toBe(OrderStatus::OutForDelivery)
        ->and($order->ready_at)->not->toBeNull()
        ->and($order->statusEvents->pluck('to_status')->all())->toBe([
            OrderStatus::PendingPayment, OrderStatus::Placed, OrderStatus::Accepted,
            OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery,
        ])
        ->and($order->statusEvents->last()->note)->toBe('The DoorDash driver picked it up.');

    doorDashEvent($restaurant, 'DASHER_DROPPED_OFF', $id)->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($this->order->courier_status)->toBe(CourierStatus::Delivered)
        ->and($this->order->completed_at)->not->toBeNull();
});

it('shows the customer DoorDash’s driver and live map', function () {
    doorDashEvent($this->menu->restaurant, 'DASHER_CONFIRMED', $this->order->public_id, ['dasher_name' => 'Priya S.'])->assertOk();

    $this->getJson("/api/v1/orders/{$this->order->public_id}?token={$this->order->tracking_token}")
        ->assertOk()
        ->assertJsonPath('data.courier', [
            'name' => 'DoorDash',
            'status' => 'driver_assigned',
            'driver_name' => 'Priya S.',
            'tracking_url' => "https://track.doordash.test/{$this->order->public_id}",
        ]);
});

it('never moves a delivery back, and applies a repeated webhook once', function () {
    $restaurant = $this->menu->restaurant;
    $id = $this->order->public_id;

    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $id)->assertOk();
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $id)->assertOk();
    // Late news from before the pickup.
    doorDashEvent($restaurant, 'DASHER_CONFIRMED_PICKUP_ARRIVAL', $id)->assertOk();

    $order = $this->order->refresh();

    expect($order->courier_status)->toBe(CourierStatus::PickedUp)
        ->and($order->statusEvents->where('to_status', OrderStatus::OutForDelivery))->toHaveCount(1);
});

it('lets DoorDash find another driver when one drops out', function () {
    doorDashEvent($this->menu->restaurant, 'DASHER_CONFIRMED', $this->order->public_id)->assertOk();
    $this->doordash->advance($this->order->public_id, 'created');

    $this->artisan('orders:sync-couriers')->assertSuccessful();

    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::Booked)
        ->and($this->order->courier_driver_name)->toBeNull();
});

it('tells the kitchen when DoorDash calls the delivery off', function () {
    doorDashEvent($this->menu->restaurant, 'DELIVERY_CANCELLED', $this->order->public_id, ['cancellation_reason' => 'dasher_not_available'])
        ->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Accepted)
        ->and($this->order->courier_status)->toBe(CourierStatus::Cancelled);

    $this->actingAs($this->staff)->getJson('/api/v1/staff/orders')
        ->assertOk()
        ->assertJsonPath('data.0.courier.problem', 'DoorDash cancelled the delivery (dasher not available). Book another driver, or deliver it yourselves.')
        ->assertJsonPath('data.0.courier.can_book', true);
});

it('tells the kitchen when the driver brings it back', function () {
    $restaurant = $this->menu->restaurant;
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $this->order->public_id)->assertOk();
    doorDashEvent($restaurant, 'DELIVERY_RETURN_INITIALIZED', $this->order->public_id)->assertOk();

    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::Returning)
        ->and($this->order->courier_problem)->toBe('The driver couldn’t deliver it and is bringing it back.');

    doorDashEvent($restaurant, 'DELIVERY_RETURNED', $this->order->public_id)->assertOk();

    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::Returned)
        ->and($this->order->courier_problem)->toBe('The driver brought it back.')
        ->and($this->order->status)->toBe(OrderStatus::OutForDelivery);

    // The kitchen can send it out again with another driver.
    $this->actingAs($this->staff)->postJson("/api/v1/staff/orders/{$this->order->public_id}/courier")
        ->assertOk()
        ->assertJsonPath('data.courier.status', 'booked');
});

it('ignores news of deliveries it didn’t book, earlier bookings and other restaurants’ orders', function () {
    $restaurant = $this->menu->restaurant;
    $other = MomoMenu::create();
    setUpDoorDash($other->restaurant);
    $theirs = app(OrderService::class)->accept(placeDeliveryOrder($other), 20, User::factory()->staffOf($other->restaurant)->create());

    // Made in DoorDash's own portal.
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', 'made-in-the-portal')->assertOk();
    // Another restaurant's delivery, sent to this restaurant's webhook.
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $theirs->public_id)->assertOk();
    // An event the platform doesn't act on.
    doorDashEvent($restaurant, 'DELIVERY_BATCHED', $this->order->public_id)->assertOk();

    expect($theirs->refresh()->status)->toBe(OrderStatus::Accepted)
        ->and($this->order->refresh()->courier_status)->toBe(CourierStatus::Booked);

    // After DoorDash calls the first booking off and the kitchen books again, the first one's news is old.
    doorDashEvent($restaurant, 'DELIVERY_CANCELLED', $this->order->public_id)->assertOk();
    $this->actingAs($this->staff)->postJson("/api/v1/staff/orders/{$this->order->public_id}/courier")->assertOk();
    doorDashEvent($restaurant, 'DASHER_PICKED_UP', $this->order->public_id)->assertOk();

    expect($this->order->refresh()->courier_status)->toBe(CourierStatus::Booked)
        ->and($this->order->status)->toBe(OrderStatus::Accepted);
});
