<?php

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusEvent;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;

beforeEach(function () {
    $this->seed(DemoRestaurantSeeder::class);

    $this->restaurant = Restaurant::query()->where('slug', DemoRestaurantSeeder::SLUG)->sole();
    $this->actingAs(User::query()->where('email', 'owner@example.com')->sole());
});

it('renders every back office page for the demo restaurant', function (string $path) {
    $this->get("/admin/{$this->restaurant->slug}{$path}")->assertOk();
})->with([
    'dashboard' => '',
    'settings' => '/profile',
    'orders' => '/orders',
    'categories' => '/menu-categories',
    'items' => '/menu-items',
    'new item' => '/menu-items/create',
    'option groups' => '/modifier-groups',
    'new option group' => '/modifier-groups/create',
    'opening hours' => '/opening-hours',
    'special hours' => '/special-hours',
    'delivery zones' => '/delivery-zones',
    'promo codes' => '/promo-codes',
    'staff' => '/staff',
]);

it('renders the edit pages for a menu item and an option group', function () {
    $item = MenuItem::query()->where('name', 'Steamed momo')->sole();
    $group = ModifierGroup::query()->where('name', 'Choose filling')->sole();

    $this->get("/admin/{$this->restaurant->slug}/menu-items/{$item->id}/edit")
        ->assertOk()
        ->assertSee('Steamed momo')
        ->assertSee('17.90');

    $this->get("/admin/{$this->restaurant->slug}/modifier-groups/{$group->id}/edit")
        ->assertOk()
        ->assertSee('Chicken');
});

it('shows an order with its items, options, totals and timeline', function () {
    $order = Order::factory()->for($this->restaurant)->delivery()->accepted()->create([
        'customer_name' => 'Ang Lhamu',
        'notes' => 'Extra napkins please',
    ]);
    $item = OrderItem::factory()->for($order)->create(['name' => 'Jhol momo', 'quantity' => 2]);
    OrderItemModifier::factory()->for($item)->create(['group_name' => 'Choose filling', 'name' => 'Pork']);
    OrderStatusEvent::factory()->for($order)->create(['from_status' => null, 'to_status' => OrderStatus::PendingPayment]);
    OrderStatusEvent::factory()->for($order)->create(['from_status' => OrderStatus::PendingPayment, 'to_status' => OrderStatus::Placed]);

    $this->get("/admin/{$this->restaurant->slug}/orders/{$order->id}")
        ->assertOk()
        ->assertSee("#{$order->display_number}")
        ->assertSee('Ang Lhamu')
        ->assertSee('2 × Jhol momo')
        ->assertSee('Choose filling: Pork')
        ->assertSee('Extra napkins please')
        ->assertSee('Includes GST');
});
