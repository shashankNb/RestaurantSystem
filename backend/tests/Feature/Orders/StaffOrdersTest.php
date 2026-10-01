<?php

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->staff = User::factory()->staffOf($this->menu->restaurant)->create();
    $this->other = MomoMenu::create();
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function paidOrder(MomoMenu $menu, array $attributes = [], string $state = 'placed'): Order
{
    return Order::factory()->for($menu->restaurant)->{$state}()->create([
        'stripe_payment_intent_id' => 'pi_test_'.Str::random(12),
        ...$attributes,
    ]);
}

it('lists the restaurant’s orders the kitchen still has to act on, oldest first', function () {
    $newer = paidOrder($this->menu, ['placed_at' => now()->subMinutes(2)]);
    $older = paidOrder($this->menu, ['placed_at' => now()->subMinutes(9)]);
    paidOrder($this->menu, [], 'completed');
    Order::factory()->for($this->menu->restaurant)->create();
    paidOrder($this->other);

    $response = $this->actingAs($this->staff)->getJson('/api/v1/staff/orders')->assertOk();

    expect(array_column($response->json('data'), 'public_id'))->toBe([$older->public_id, $newer->public_id])
        ->and($response->json('data.0.customer.name'))->toBe($older->customer_name);
});

it('says when each new order will be rejected if nobody accepts it', function () {
    $new = paidOrder($this->menu, ['placed_at' => now()->subMinutes(3)]);
    paidOrder($this->menu, [], 'accepted');
    $minutes = $this->menu->restaurant->auto_reject_minutes;

    $response = $this->actingAs($this->staff)->getJson('/api/v1/staff/orders')->assertOk();

    expect($response->json('data.0.public_id'))->toBe($new->public_id)
        ->and($response->json('data.0.accept_by'))->toBe(now()->subMinutes(3)->addMinutes($minutes)->utc()->toIso8601ZuluString())
        ->and($response->json('data.1.accept_by'))->toBeNull();
});

it('filters the queue by status', function () {
    paidOrder($this->menu);
    $accepted = paidOrder($this->menu, [], 'accepted');

    $response = $this->actingAs($this->staff)->getJson('/api/v1/staff/orders?status=accepted,preparing')->assertOk();

    expect(array_column($response->json('data'), 'public_id'))->toBe([$accepted->public_id]);
});

it('accepts an order with a prep time', function () {
    $order = paidOrder($this->menu);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 20])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.prep_minutes', 20)
        ->assertJsonPath('data.estimated_ready_at', '2026-10-05T07:20:00Z');
});

it('rejects an order with a reason and refunds it in full', function () {
    $order = paidOrder($this->menu);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/reject", ['reason' => 'We’ve run out of pork'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $order->refresh();

    expect($order->rejection_reason)->toBe('We’ve run out of pork')
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->stripe_refund_id)->toBe($this->payments->refunds[0]['id'])
        ->and($this->payments->refunds[0])->toMatchArray(['order' => $order->public_id, 'key' => "refund-{$order->public_id}", 'amount' => $order->total_cents]);
});

it('asks for a reason when rejecting', function () {
    $order = paidOrder($this->menu);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

it('moves an order along to collection', function () {
    $order = paidOrder($this->menu, [], 'accepted');

    foreach (['preparing', 'ready', 'completed'] as $status) {
        $this->actingAs($this->staff)
            ->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status);
    }
});

it('sends a delivery order out before completing it', function () {
    $order = paidOrder($this->menu, ['fulfilment_type' => FulfilmentType::Delivery], 'ready');

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'An order that’s ready can’t be marked completed.');

    $this->actingAs($this->staff)->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'out_for_delivery'])->assertOk();
    $this->actingAs($this->staff)->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'completed'])->assertOk();
});

it('refuses moves the lifecycle doesn’t allow', function () {
    $order = paidOrder($this->menu);

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'ready'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 15])
        ->assertOk();

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 15])
        ->assertUnprocessable();
});

it('cancels a paid order and refunds it', function () {
    $order = paidOrder($this->menu, [], 'preparing');

    $this->actingAs($this->staff)
        ->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'cancelled', 'note' => 'Customer called to cancel'])
        ->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->statusEvents()->latest('id')->first()?->note)->toBe('Customer called to cancel');
});

it('denies staff access to other restaurants’ orders', function (Closure $request) {
    $theirs = paidOrder($this->other);

    $request($theirs)->assertForbidden();

    expect($theirs->refresh()->status)->toBe(OrderStatus::Placed);
})->with([
    'accept' => [fn (Order $order) => test()->actingAs(test()->staff)->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 15])],
    'reject' => [fn (Order $order) => test()->actingAs(test()->staff)->postJson("/api/v1/staff/orders/{$order->public_id}/reject", ['reason' => 'Not ours'])],
    'status' => [fn (Order $order) => test()->actingAs(test()->staff)->postJson("/api/v1/staff/orders/{$order->public_id}/status", ['status' => 'cancelled'])],
    'queue' => [fn (Order $order) => test()->actingAs(test()->staff)->getJson("/api/v1/staff/orders?restaurant={$order->restaurant->slug}")],
]);

it('denies customers the kitchen screens', function () {
    $order = paidOrder($this->menu);
    $customer = User::factory()->create();

    $this->actingAs($customer)->getJson('/api/v1/staff/orders')
        ->assertForbidden()
        ->assertJsonPath('message', 'This account doesn’t have staff access to a restaurant.');

    $this->actingAs($customer)->postJson("/api/v1/staff/orders/{$order->public_id}/accept", ['prep_minutes' => 15])->assertForbidden();
});

it('asks someone who works at two restaurants which one they mean', function () {
    $this->other->restaurant->memberships()->create(['user_id' => $this->staff->id, 'role' => 'staff']);

    $this->actingAs($this->staff)->getJson('/api/v1/staff/orders')->assertUnprocessable()->assertJsonValidationErrors('restaurant');
    $this->actingAs($this->staff)->getJson("/api/v1/staff/orders?restaurant={$this->other->restaurant->slug}")->assertOk();
});

it('pauses and resumes online ordering', function () {
    $this->actingAs($this->staff)
        ->patchJson('/api/v1/staff/restaurant', ['is_accepting_orders' => false])
        ->assertOk()
        ->assertJsonPath('data.is_accepting_orders', false);

    $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")
        ->assertJsonPath('data.status.can_order_asap', false);

    $this->actingAs($this->staff)->patchJson('/api/v1/staff/restaurant', ['is_accepting_orders' => true])->assertOk();
    expect($this->menu->restaurant->refresh()->is_accepting_orders)->toBeTrue();
});

it('marks items and options sold out, shown on the menu straight away', function () {
    $this->actingAs($this->staff)
        ->patchJson("/api/v1/staff/menu-items/{$this->menu->lassi->id}", ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('data.is_available', false);

    $this->actingAs($this->staff)
        ->patchJson("/api/v1/staff/modifier-options/{$this->menu->pork->id}", ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('data.is_available', false);

    $menu = $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}/menu")->json('data.categories');

    expect($menu[1]['items'][0]['is_available'])->toBeFalse()
        ->and($menu[0]['items'][0]['modifier_groups'][0]['options'][1])->toMatchArray(['name' => 'Pork', 'is_available' => false]);
});

it('denies staff the switches of other restaurants', function () {
    $this->actingAs($this->staff)->patchJson("/api/v1/staff/menu-items/{$this->other->lassi->id}", ['is_available' => false])->assertForbidden();
    $this->actingAs($this->staff)->patchJson("/api/v1/staff/modifier-options/{$this->other->pork->id}", ['is_available' => false])->assertForbidden();
    $this->actingAs($this->staff)->patchJson('/api/v1/staff/restaurant', ['restaurant' => $this->other->restaurant->slug, 'is_accepting_orders' => false])->assertForbidden();

    expect($this->other->lassi->refresh()->is_available)->toBeTrue()
        ->and($this->other->restaurant->refresh()->is_accepting_orders)->toBeTrue();
});

it('requires signing in', function () {
    $this->getJson('/api/v1/staff/orders')->assertUnauthorized();
});
