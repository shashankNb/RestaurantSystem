<?php

use App\Enums\OrderStatus;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusEvent;
use App\Models\Restaurant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('gives every restaurant-owned table a restaurant_id foreign key', function (string $table) {
    $foreignKeys = collect(Schema::getForeignKeys($table))
        ->filter(fn (array $key): bool => $key['columns'] === ['restaurant_id'] && $key['foreign_table'] === 'restaurants');

    expect($foreignKeys)->toHaveCount(1);
})->with([
    'restaurant_user',
    'opening_hours',
    'special_hours',
    'menu_categories',
    'menu_items',
    'modifier_groups',
    'modifier_options',
    'delivery_zones',
    'promo_codes',
    'orders',
    'order_items',
    'order_item_modifiers',
    'order_status_events',
    'push_tokens',
]);

it('indexes the order queue by restaurant, status and time', function () {
    $columns = collect(Schema::getIndexes('orders'))->pluck('columns');

    expect($columns)->toContain(['restaurant_id', 'status', 'created_at'])
        ->and($columns)->toContain(['restaurant_id', 'business_date', 'order_number']);
});

it('indexes every foreign key column', function () {
    $tables = collect(Schema::getTables())->pluck('name');

    foreach ($tables as $table) {
        $indexed = collect(Schema::getIndexes($table))->map(fn (array $index): string => $index['columns'][0]);

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            expect($indexed)->toContain($foreignKey['columns'][0]);
        }
    }
});

it('copies restaurant_id from the parent when child rows are created', function () {
    $restaurant = Restaurant::factory()->create();

    $category = MenuCategory::factory()->for($restaurant)->create();
    $item = MenuItem::factory()->for($category, 'category')->create();
    $group = ModifierGroup::factory()->for($restaurant)->create();
    $option = ModifierOption::factory()->for($group, 'group')->create();

    $order = Order::factory()->for($restaurant)->create();
    $orderItem = OrderItem::factory()->for($order)->create();
    $modifier = OrderItemModifier::factory()->for($orderItem)->create();
    $event = OrderStatusEvent::factory()->for($order)->create();

    foreach ([$item, $option, $orderItem, $modifier, $event] as $child) {
        expect($child->restaurant_id)->toBe($restaurant->id);
    }
});

it('scopes queries to a restaurant', function () {
    [$mine, $theirs] = Restaurant::factory()->count(2)->create();
    MenuCategory::factory()->for($mine)->count(2)->create();
    MenuCategory::factory()->for($theirs)->create();

    expect(MenuCategory::query()->forRestaurant($mine)->count())->toBe(2)
        ->and(MenuCategory::query()->forRestaurant($theirs->id)->count())->toBe(1);
});

it('gives orders a ULID public id and a zero-padded kitchen number', function () {
    $order = Order::factory()->placed()->create(['order_number' => 42]);

    expect($order->public_id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($order->getKey())->toBeInt()
        ->and($order->display_number)->toBe('042')
        ->and($order->status)->toBe(OrderStatus::Placed)
        ->and($order->toArray())->not->toHaveKeys(['tracking_token', 'idempotency_key', 'push_token']);
});

it('leaves the kitchen number empty until the order is paid', function () {
    expect(Order::factory()->create())
        ->order_number->toBeNull()
        ->display_number->toBeNull();
});

it('refuses two orders with the same daily number at one restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    Order::factory()->for($restaurant)->placed()->create(['order_number' => 7, 'business_date' => '2026-10-02']);

    expect(fn () => Order::factory()->for($restaurant)->placed()->create(['order_number' => 7, 'business_date' => '2026-10-02']))
        ->toThrow(UniqueConstraintViolationException::class);
});
