<?php

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\OrderMessages;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->menu->restaurant->update(['dine_in_enabled' => true]);
    $this->table = DiningTable::factory()->for($this->menu->restaurant)->create(['label' => '12']);

    // Monday 6 pm: open.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function dineInQuote(MomoMenu $menu, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders/quote", [
        'fulfilment_type' => 'dine_in',
        'table' => '12',
        'items' => [['menu_item_id' => $menu->steamed->id, 'quantity' => 1, 'modifier_option_ids' => [$menu->chicken->id, $menu->mild->id]]],
        ...$overrides,
    ]);
}

it('prices an order at a table with no delivery fee or minimum', function () {
    dineInQuote($this->menu)
        ->assertOk()
        ->assertJsonPath('data.can_place_order', true)
        ->assertJsonPath('data.delivery_fee_cents', 0)
        ->assertJsonPath('data.total_cents', 1790)
        ->assertJsonPath('data.fulfilment.type', 'dine_in')
        ->assertJsonPath('data.fulfilment.table', '12');
});

it('matches the table whatever its case', function () {
    $this->table->update(['label' => 'A3']);

    dineInQuote($this->menu, ['table' => 'a3'])->assertJsonPath('data.fulfilment.table', 'A3');
});

it('refuses a table the restaurant doesn’t have, or has turned off', function (Closure $setUp, string $table) {
    $setUp($this->table);

    dineInQuote($this->menu, ['table' => $table])
        ->assertOk()
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0.code', 'unknown_table')
        ->assertJsonPath('data.errors.0.field', 'table')
        ->assertJsonPath('data.errors.0.message', "We can’t find table {$table}. Check the number on your table and choose it again.");
})->with([
    'not one of its tables' => [fn () => null, '99'],
    'turned off' => [function (DiningTable $table) {
        // Another table keeps dine in on.
        DiningTable::factory()->for($table->restaurant)->create(['label' => '1']);
        $table->update(['is_active' => false]);
    }, '12'],
    'another restaurant’s' => [fn () => DiningTable::factory()->for(MomoMenu::create()->restaurant)->create(['label' => '40']), '40'],
]);

it('refuses dine in when the restaurant doesn’t offer it', function (Closure $setUp) {
    $setUp($this->menu, $this->table);

    dineInQuote($this->menu)
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0.code', 'fulfilment_unavailable')
        ->assertJsonPath('data.errors.0.field', 'fulfilment_type');
})->with([
    'switched off' => [fn (MomoMenu $menu) => $menu->restaurant->update(['dine_in_enabled' => false])],
    'no tables taking orders' => [fn (MomoMenu $menu, DiningTable $table) => $table->update(['is_active' => false])],
]);

it('takes orders at a table for now only', function () {
    dineInQuote($this->menu, ['scheduled_for' => '2026-10-05T09:00:00Z'])
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0.code', 'invalid_time')
        ->assertJsonPath('data.errors.0.message', 'Orders at a table are for now. Choose as soon as possible.');
});

it('takes no table orders while closed or paused, without suggesting a later time', function (Closure $setUp, string $code, string $message) {
    $setUp($this);

    dineInQuote($this->menu)
        ->assertJsonPath('data.can_place_order', false)
        ->assertJsonPath('data.errors.0.code', $code)
        ->assertJsonPath('data.errors.0.message', $message);
})->with([
    'closed' => [fn ($test) => $test->travelTo(CarbonImmutable::parse('2026-10-05 15:00', 'Australia/Melbourne')), 'closed', 'We’re closed right now and not taking orders.'],
    'paused' => [fn ($test) => $test->menu->restaurant->update(['is_accepting_orders' => false]), 'paused', 'We’ve paused orders for now. Ask a member of staff.'],
]);

it('asks for the table', function () {
    dineInQuote($this->menu, ['table' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['table' => 'Choose your table.']);
});

it('places an order for the table', function () {
    $response = $this->withHeader('Idempotency-Key', 'dine-in-key-0001')
        ->postJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/orders", $this->menu->orderPayload([
            'fulfilment_type' => 'dine_in',
            'table' => '12',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.order.fulfilment_type', 'dine_in')
        ->assertJsonPath('data.order.table', '12');

    $order = Order::query()->where('public_id', $response->json('data.order.public_id'))->sole();

    expect($order->fulfilment_type)->toBe(FulfilmentType::DineIn)
        ->and($order->dining_table_id)->toBe($this->table->id)
        ->and($order->table_label)->toBe('12')
        ->and($order->delivery_fee_cents)->toBe(0);
});

it('keeps the table’s name on the order when the table is removed', function () {
    $order = Order::factory()->for($this->menu->restaurant)->placed()->create([
        'fulfilment_type' => FulfilmentType::DineIn,
        'dining_table_id' => $this->table->id,
        'table_label' => '12',
    ]);

    $this->table->delete();

    expect($order->refresh())->dining_table_id->toBeNull()->table_label->toBe('12');
});

it('lists the tables customers can choose, in the restaurant’s order', function () {
    DiningTable::factory()->for($this->menu->restaurant)->create(['label' => '2']);
    DiningTable::factory()->for($this->menu->restaurant)->create(['label' => 'Bar', 'sort_order' => 1]);
    DiningTable::factory()->for($this->menu->restaurant)->inactive()->create(['label' => '7']);

    $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")
        ->assertJsonPath('data.fulfilment.dine_in.enabled', true)
        ->assertJsonPath('data.fulfilment.dine_in.tables', ['2', '12', 'Bar']);

    $this->menu->restaurant->update(['dine_in_enabled' => false]);

    $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")
        ->assertJsonPath('data.fulfilment.dine_in.enabled', false)
        ->assertJsonPath('data.fulfilment.dine_in.tables', []);
});

it('offers no times for later at a table', function () {
    $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/slots?fulfilment_type=dine_in")
        ->assertOk()
        ->assertJsonPath('data.asap.available', true)
        ->assertJsonPath('data.asap.estimated_minutes', $this->menu->restaurant->default_prep_minutes)
        ->assertJsonPath('data.slots', []);
});

it('serves a ready table order without sending it out for delivery', function () {
    $order = Order::factory()->for($this->menu->restaurant)->ready()->create(['fulfilment_type' => FulfilmentType::DineIn, 'table_label' => '12']);
    $orders = app(OrderService::class);

    expect($orders->canTransition($order, OrderStatus::Completed))->toBeTrue()
        ->and($orders->canTransition($order, OrderStatus::OutForDelivery))->toBeFalse();
});

it('shows the kitchen which table it’s for', function () {
    $staff = User::factory()->staffOf($this->menu->restaurant)->create();
    Order::factory()->for($this->menu->restaurant)->placed()->create(['fulfilment_type' => FulfilmentType::DineIn, 'table_label' => '12']);

    $this->actingAs($staff)->getJson('/api/v1/staff/orders')->assertJsonPath('data.0.table', '12');
});

it('tells the customer their order is coming to their table', function () {
    $order = Order::factory()->for($this->menu->restaurant)->ready()->create([
        'fulfilment_type' => FulfilmentType::DineIn,
        'table_label' => '12',
        'order_number' => 7,
    ]);

    expect(OrderMessages::forCustomer($order))->toBe([
        'title' => 'Order 007 is ready',
        'body' => 'We’re bringing it to table 12.',
    ]);
});
