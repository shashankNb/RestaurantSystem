<?php

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PushToken;
use App\Models\User;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->customer = User::factory()->create();
});

describe('tracking', function () {
    beforeEach(function () {
        $this->order = Order::factory()->for($this->menu->restaurant)->accepted()->create([
            'user_id' => $this->customer->id,
            'customer_name' => 'Sam Taylor',
            'customer_phone' => '0491 570 110',
            'delivery_line1' => '12 Southbank Boulevard',
        ]);
        $this->order->statusEvents()->create(['from_status' => null, 'to_status' => 'placed']);
        $this->order->statusEvents()->create(['from_status' => 'placed', 'to_status' => 'accepted']);
    });

    it('shows a guest their order with its tracking token', function () {
        $response = $this->getJson("/api/v1/orders/{$this->order->public_id}?token={$this->order->tracking_token}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $this->order->public_id)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.restaurant.slug', $this->menu->restaurant->slug)
            ->assertJsonPath('data.timeline.*.status', ['placed', 'accepted']);

        expect(json_encode($response->json()))->not->toContain('Sam Taylor')->not->toContain('0491')->not->toContain('Southbank Boulevard');
    });

    it('gives each item’s menu IDs, so the app can order it again', function () {
        $item = $this->order->items()->create([
            'menu_item_id' => $this->menu->steamed->id, 'name' => 'Steamed momo', 'unit_price_cents' => 1890, 'quantity' => 1, 'line_total_cents' => 1890,
        ]);
        $item->modifiers()->create(['modifier_option_id' => $this->menu->pork->id, 'group_name' => 'Choose filling', 'name' => 'Pork', 'price_delta_cents' => 100]);
        $url = "/api/v1/orders/{$this->order->public_id}?token={$this->order->tracking_token}";

        $this->getJson($url)
            ->assertJsonPath('data.items.0.menu_item_id', $this->menu->steamed->id)
            ->assertJsonPath('data.items.0.modifiers.0.modifier_option_id', $this->menu->pork->id);

        // Deleted from the menu since: the order keeps its names and prices, without the IDs.
        $this->menu->steamed->delete();
        $this->menu->pork->delete();

        $this->getJson($url)
            ->assertJsonPath('data.items.0.name', 'Steamed momo')
            ->assertJsonPath('data.items.0.menu_item_id', null)
            ->assertJsonPath('data.items.0.modifiers.0.modifier_option_id', null);
    });

    it('shows the signed-in customer their order without a token', function () {
        $this->actingAs($this->customer)->getJson("/api/v1/orders/{$this->order->public_id}")->assertOk();
    });

    it('hides the order from everyone else', function (Closure $request) {
        $request($this->order)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    })->with([
        'no token' => [fn (Order $order) => test()->getJson("/api/v1/orders/{$order->public_id}")],
        'wrong token' => [fn (Order $order) => test()->getJson("/api/v1/orders/{$order->public_id}?token=guess")],
        'another customer' => [fn (Order $order) => test()->actingAs(User::factory()->create())->getJson("/api/v1/orders/{$order->public_id}")],
        'unknown order' => [fn () => test()->getJson('/api/v1/orders/01abcdefghjkmnpqrstvwxyz0?token=x')],
    ]);
});

describe('order history', function () {
    it('lists the customer’s paid orders, newest first', function () {
        $older = Order::factory()->for($this->menu->restaurant)->completed()->create(['user_id' => $this->customer->id, 'placed_at' => now()->subDays(2)]);
        $newer = Order::factory()->for($this->menu->restaurant)->placed()->create(['user_id' => $this->customer->id, 'placed_at' => now()->subHour()]);
        Order::factory()->for($this->menu->restaurant)->create(['user_id' => $this->customer->id]);
        Order::factory()->for($this->menu->restaurant)->placed()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->customer)
            ->getJson('/api/v1/me/orders')
            ->assertOk()
            ->assertJsonPath('data.*.public_id', [$newer->public_id, $older->public_id])
            ->assertJsonPath('meta.total', 2);
    });

    it('requires signing in', function () {
        $this->getJson('/api/v1/me/orders')->assertUnauthorized();
    });
});

describe('saved addresses', function () {
    it('adds, lists, changes and removes addresses', function () {
        $id = $this->actingAs($this->customer)->postJson('/api/v1/me/addresses', [
            'label' => 'Home', 'line1' => '12 Southbank Boulevard', 'suburb' => 'Southbank', 'state' => 'VIC', 'postcode' => '3006',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->customer)->getJson('/api/v1/me/addresses')->assertJsonPath('data.0.line1', '12 Southbank Boulevard');

        $this->actingAs($this->customer)->patchJson("/api/v1/me/addresses/{$id}", [
            'label' => 'Home', 'line1' => '14 Southbank Boulevard', 'suburb' => 'Southbank', 'state' => 'VIC', 'postcode' => '3006',
        ])->assertOk()->assertJsonPath('data.line1', '14 Southbank Boulevard');

        $this->actingAs($this->customer)->deleteJson("/api/v1/me/addresses/{$id}")->assertNoContent();

        expect(CustomerAddress::query()->count())->toBe(0);
    });

    it('asks for a proper address', function () {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/me/addresses', ['postcode' => '300'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['line1', 'suburb', 'state', 'postcode']);
    });

    it('treats someone else’s address as not found', function () {
        $theirs = CustomerAddress::factory()->for(User::factory())->create();

        $this->actingAs($this->customer)->deleteJson("/api/v1/me/addresses/{$theirs->id}")->assertNotFound();

        expect($theirs->fresh())->not->toBeNull();
    });
});

describe('push tokens', function () {
    it('registers a signed-in customer’s device for the restaurant', function () {
        $this->actingAs($this->customer)->postJson('/api/v1/push-tokens', [
            'expo_push_token' => 'ExponentPushToken[device-1]',
            'platform' => 'ios',
            'restaurant' => $this->menu->restaurant->slug,
        ])->assertCreated();

        expect(PushToken::query()->sole())
            ->user_id->toBe($this->customer->id)
            ->restaurant_id->toBe($this->menu->restaurant->id);
    });

    it('moves a device to whoever signs in on it', function () {
        PushToken::factory()->for($this->menu->restaurant)->for(User::factory())->create(['expo_push_token' => 'ExponentPushToken[shared]']);

        $this->actingAs($this->customer)->postJson('/api/v1/push-tokens', [
            'expo_push_token' => 'ExponentPushToken[shared]', 'platform' => 'android', 'restaurant' => $this->menu->restaurant->slug,
        ])->assertCreated();

        expect(PushToken::query()->sole()->user_id)->toBe($this->customer->id);
    });

    it('lets a guest follow one order with its tracking token', function () {
        $order = Order::factory()->for($this->menu->restaurant)->placed()->create();

        $this->postJson('/api/v1/push-tokens', [
            'expo_push_token' => 'ExponentPushToken[guest]',
            'platform' => 'web',
            'order' => ['public_id' => $order->public_id, 'tracking_token' => $order->tracking_token],
        ])->assertCreated();

        expect($order->refresh()->push_token)->toBe('ExponentPushToken[guest]');

        $this->postJson('/api/v1/push-tokens', [
            'expo_push_token' => 'ExponentPushToken[intruder]',
            'platform' => 'web',
            'order' => ['public_id' => $order->public_id, 'tracking_token' => 'wrong'],
        ])->assertNotFound();

        expect($order->refresh()->push_token)->toBe('ExponentPushToken[guest]');
    });

    it('accepts only Expo push tokens', function () {
        $this->actingAs($this->customer)->postJson('/api/v1/push-tokens', [
            'expo_push_token' => 'not-a-token', 'platform' => 'ios', 'restaurant' => $this->menu->restaurant->slug,
        ])->assertUnprocessable()->assertJsonValidationErrors('expo_push_token');
    });
});
