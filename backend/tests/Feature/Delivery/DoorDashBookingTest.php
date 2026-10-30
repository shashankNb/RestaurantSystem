<?php

use App\Delivery\CourierUnavailable;
use App\Delivery\DoorDash\DoorDashDelivery;
use App\Delivery\DoorDash\DoorDashGateway;
use App\Enums\CourierStatus;
use App\Enums\OrderStatus;
use App\Jobs\BookCourier;
use App\Models\Order;
use App\Models\User;
use App\Services\CourierService;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
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

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function acceptForDelivery(Order $order, int $prepMinutes = 20): TestResponse
{
    return test()->actingAs(test()->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => $prepMinutes]);
}

/** @return array<string, mixed> DoorDash's booking request for the order */
function bookingRequestFor(FakeDoorDashGateway $doordash, string $externalId): array
{
    return collect($doordash->quotes)->firstOrFail(fn (array $quote): bool => $quote['external_delivery_id'] === $externalId);
}

it('books a DoorDash driver when the kitchen accepts the order, to collect it when it’s ready', function () {
    $order = placeDeliveryOrder($this->menu);

    acceptForDelivery($order)
        ->assertOk()
        ->assertJsonPath('data.courier.status', 'booked')
        ->assertJsonPath('data.courier.tracking_url', "https://track.doordash.test/{$order->public_id}")
        ->assertJsonPath('data.courier.support_reference', '12341');

    $order->refresh();
    $request = bookingRequestFor($this->doordash, $order->public_id);

    expect($order->courier_status)->toBe(CourierStatus::Booked)
        ->and($order->courier_delivery_id)->toBe($order->public_id)
        ->and($order->courier_fee_cents)->toBe(975)
        ->and($this->doordash->accepted)->toBe([$order->public_id])
        ->and($request)->toMatchArray([
            'pickup_business_name' => $this->menu->restaurant->name,
            'pickup_phone_number' => '+61355501234',
            'pickup_instructions' => "Order {$order->display_number} for Sam.",
            'dropoff_address' => '12 Southbank Boulevard, Southbank VIC 3006, Australia',
            'dropoff_phone_number' => '+61491570110',
            'dropoff_contact_given_name' => 'Sam',
            'dropoff_contact_family_name' => 'Taylor',
            // The apartment goes to the driver, not the map.
            'dropoff_instructions' => 'Apartment 1204. Buzz 1204.',
            'dropoff_contact_send_notifications' => true,
            'order_value' => 3580,
            'items' => [['name' => 'Steamed momo', 'quantity' => 2, 'description' => 'Chicken, Mild', 'price' => 1790]],
            'pickup_time' => now()->addMinutes(20)->utc()->toIso8601ZuluString(),
        ])
        ->and($request)->not->toHaveKey('dropoff_time');
});

it('books a time chosen more than an hour ahead for when it should arrive', function () {
    // 8 pm, two hours from now.
    $order = placeDeliveryOrder($this->menu, ['scheduled_for' => '2026-10-05T09:00:00Z']);

    acceptForDelivery($order)->assertOk();

    $request = bookingRequestFor($this->doordash, $order->public_id);

    expect($request['dropoff_time'])->toBe('2026-10-05T09:00:00Z')
        ->and($request)->not->toHaveKey('pickup_time');
});

it('collects a time chosen within the hour in time for its delivery', function () {
    // 6:45 pm: the zone delivers in 45 minutes, so the driver collects it straight away.
    $order = placeDeliveryOrder($this->menu, ['scheduled_for' => '2026-10-05T07:45:00Z']);
    acceptForDelivery($order)->assertOk();

    expect(bookingRequestFor($this->doordash, $order->public_id))->not->toHaveKeys(['pickup_time', 'dropoff_time']);
});

it('says why DoorDash wouldn’t book a driver, and books one again from the kitchen', function () {
    $order = placeDeliveryOrder($this->menu);
    $this->doordash->refuseNextQuote = 'dropoff_address: Address is outside of the coverage area';

    acceptForDelivery($order)
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.courier.status', 'failed')
        ->assertJsonPath('data.courier.problem', 'DoorDash couldn’t book a driver (dropoff_address: Address is outside of the coverage area). Book again, or deliver it yourselves.')
        ->assertJsonPath('data.courier.can_book', true);

    $this->postJson("/api/v1/staff/orders/{$order->public_id}/courier")
        ->assertOk()
        ->assertJsonPath('data.courier.status', 'booked')
        ->assertJsonPath('data.courier.problem', null)
        ->assertJsonPath('data.courier.can_book', false);

    expect($order->refresh()->courier_delivery_id)->toBe("{$order->public_id}-2")
        ->and($this->doordash->accepted)->toBe(["{$order->public_id}-2"]);
});

it('tells the kitchen straight away when DoorDash refuses a booking from the kitchen', function () {
    $order = placeDeliveryOrder($this->menu);
    $this->doordash->refuseNextQuote = 'pickup_time: Pickup time is too soon';
    acceptForDelivery($order)->assertOk();
    $this->doordash->refuseNextQuote = 'pickup_time: Pickup time is too soon';

    $this->postJson("/api/v1/staff/orders/{$order->public_id}/courier")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['courier' => 'DoorDash couldn’t book a driver (pickup_time: Pickup time is too soon). Book again, or deliver it yourselves.']);
});

it('tells the kitchen when DoorDash never answers', function () {
    Queue::fake([BookCourier::class]);
    $order = placeDeliveryOrder($this->menu);
    app(OrderService::class)->accept($order, 20, $this->staff);
    Queue::assertPushed(BookCourier::class);
    $this->doordash->failNext = true;

    // The queue tries again while DoorDash can't be reached…
    expect(fn () => (new BookCourier($order->id))->handle(app(CourierService::class)))->toThrow(CourierUnavailable::class);

    // …and once it gives up, the kitchen is told.
    (new BookCourier($order->id))->failed(new RuntimeException('DoorDash is down'));

    expect($order->refresh()->courier_status)->toBe(CourierStatus::Failed)
        ->and($order->courier_problem)->toBe('DoorDash didn’t answer, so no driver is booked. Book again, or deliver it yourselves.');
});

it('never books two drivers when the answer to a booking is lost', function (string $doordashHas) {
    Queue::fake([BookCourier::class]);
    $order = placeDeliveryOrder($this->menu);
    app(OrderService::class)->accept($order, 20, $this->staff);

    // DoorDash got the booking, but its answer never came back.
    $order->forceFill(['courier_attempts' => 1, 'courier_delivery_id' => $order->public_id])->save();
    $this->doordash->deliveries[$order->public_id] = new DoorDashDelivery($order->public_id, $doordashHas, 975, 'AUD');

    $order = app(CourierService::class)->book($order);

    $bookingRequests = collect($this->doordash->quotes)
        ->pluck('external_delivery_id')
        ->filter(fn (string $id): bool => str_starts_with($id, $order->public_id));

    expect($order->courier_status)->toBe(CourierStatus::Booked)
        ->and($order->courier_delivery_id)->toBe($order->public_id)
        ->and($bookingRequests->all())->toBe([]);
})->with([
    'booked' => ['created'],
    'quoted, not yet accepted' => ['quote'],
]);

it('calls the driver off when the order is cancelled', function () {
    $order = placeDeliveryOrder($this->menu);
    acceptForDelivery($order)->assertOk();

    $this->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'cancelled', 'note' => 'Out of momos'])->assertOk();

    expect($this->doordash->cancelled)->toBe([$order->public_id])
        ->and($order->refresh()->courier_status)->toBe(CourierStatus::Cancelled)
        ->and($order->courier_problem)->toBeNull();
});

it('says to call DoorDash when its driver can’t be called off any more', function () {
    $order = placeDeliveryOrder($this->menu);
    acceptForDelivery($order)->assertOk();
    $this->doordash->advance($order->public_id, 'confirmed');

    $this->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'cancelled'])->assertOk();

    expect($this->doordash->cancelled)->toBe([])
        ->and($order->refresh()->courier_problem)->toBe('DoorDash wouldn’t cancel the driver (Cancel is not allowed). Call DoorDash support, reference 12341.');
});

it('catches up with DoorDash when its webhook is missed', function () {
    $order = placeDeliveryOrder($this->menu);
    acceptForDelivery($order)->assertOk();

    $this->doordash->advance($order->public_id, 'picked_up');
    $this->artisan('orders:sync-couriers')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::OutForDelivery)
        ->and($order->courier_status)->toBe(CourierStatus::PickedUp)
        ->and($order->courier_driver_name)->toBe('Priya S.');

    $this->doordash->advance($order->public_id, 'delivered');
    $this->artisan('orders:sync-couriers')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->courier_status)->toBe(CourierStatus::Delivered);
});

it('gives up on a booking DoorDash never got, so the kitchen can book again', function () {
    Queue::fake([BookCourier::class]);
    $order = placeDeliveryOrder($this->menu);
    app(OrderService::class)->accept($order, 20, $this->staff);
    $order->forceFill(['courier_attempts' => 1, 'courier_delivery_id' => $order->public_id, 'courier_updated_at' => now()])->save();

    // Still being booked, perhaps: left alone.
    $this->artisan('orders:sync-couriers')->assertSuccessful();
    expect($order->refresh()->courier_status)->toBeNull();

    $this->travel(6)->minutes();
    $this->artisan('orders:sync-couriers')->assertSuccessful();

    expect($order->refresh()->courier_status)->toBe(CourierStatus::Failed)
        ->and($order->courier_problem)->toBe('DoorDash didn’t book a driver. Book again, or deliver it yourselves.');
});

it('only books from the kitchen when there’s no driver on the way', function () {
    $booked = placeDeliveryOrder($this->menu);
    acceptForDelivery($booked)->assertOk();
    // Numbered clear of the orders placed through the API today.
    $pickup = Order::factory()->for($this->menu->restaurant)->accepted()->create(['order_number' => 500]);

    $this->postJson("/api/v1/staff/orders/{$booked->public_id}/courier")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['courier' => 'A DoorDash driver is already booked for this order.']);

    $this->postJson("/api/v1/staff/orders/{$pickup->public_id}/courier")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['courier' => 'DoorDash doesn’t deliver this order. Deliver it yourselves.']);
});

it('keeps other restaurants’ staff from booking drivers', function () {
    $order = placeDeliveryOrder($this->menu);
    $other = MomoMenu::create();

    $this->actingAs(User::factory()->staffOf($other->restaurant)->create())
        ->postJson("/api/v1/staff/orders/{$order->public_id}/courier")
        ->assertForbidden();
});
