<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->url = "/api/v1/restaurants/{$this->menu->restaurant->slug}/orders";
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function placeOrder(array $payload, string $key = 'key-0001-abcdef'): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)->postJson(test()->url, $payload);
}

it('creates an order waiting for payment, with a PaymentIntent for the server’s total', function () {
    $response = placeOrder($this->menu->deliveryPayload(['promo_code' => 'momo10']))->assertCreated();

    $order = Order::query()->sole();
    $intent = $this->payments->intents[$order->stripe_payment_intent_id];

    // 2 × 17.90 = 35.80, 10% off = 3.58, + 6.00 delivery.
    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($order->total_cents)->toBe(3580 - 358 + 600)
        ->and($order->gst_cents)->toBe(347)
        ->and($order->order_number)->toBeNull()
        ->and($intent->amountCents)->toBe(3822)
        ->and($response->json('data.payment'))->toBe([
            'payment_intent_id' => $intent->id,
            'client_secret' => $intent->clientSecret,
            'status' => 'requires_payment_method',
            'amount_cents' => 3822,
            'currency' => 'aud',
        ])
        ->and($response->json('data.order.status'))->toBe('pending_payment')
        ->and($response->json('data.tracking_token'))->toBe($order->tracking_token);
});

it('keeps a snapshot of the order, customer and address', function () {
    placeOrder($this->menu->deliveryPayload(['items' => [
        ['menu_item_id' => $this->menu->steamed->id, 'quantity' => 2, 'modifier_option_ids' => [$this->menu->pork->id, $this->menu->tomatoAchar->id, $this->menu->hot->id], 'notes' => 'Extra crispy'],
    ]]))->assertCreated();

    $order = Order::query()->with('items.modifiers')->sole();
    $item = $order->items->sole();

    expect($order->customer_name)->toBe('Sam Taylor')
        ->and($order->customer_email)->toBe('sam@example.com')
        ->and($order->delivery_line1)->toBe('12 Southbank Boulevard')
        ->and($order->delivery_postcode)->toBe('3006')
        ->and($order->delivery_zone_id)->toBe($this->menu->zone->id)
        ->and($order->notes)->toBe('Ring when you’re here')
        ->and($item->name)->toBe('Steamed momo')
        ->and($item->unit_price_cents)->toBe(1990)
        ->and($item->notes)->toBe('Extra crispy')
        ->and($item->modifiers->map(fn ($modifier) => "{$modifier->group_name}: {$modifier->name}")->all())
        ->toBe(['Choose filling: Pork', 'Sauce: Tomato achar', 'Spice level: Hot'])
        ->and($order->statusEvents()->sole()->to_status)->toBe(OrderStatus::PendingPayment);

    // Later menu edits don't change the order.
    $this->menu->steamed->update(['name' => 'Renamed momo', 'price_cents' => 9999]);
    expect($order->items()->sole()->name)->toBe('Steamed momo');
});

it('charges the server’s prices whatever the app sends', function () {
    $payload = $this->menu->orderPayload(['total_cents' => 1, 'subtotal_cents' => 1]);
    $payload['items'][0] += ['unit_price_cents' => 1, 'price_cents' => 1];

    placeOrder($payload)->assertCreated();

    expect(Order::query()->sole()->total_cents)->toBe(3580)
        ->and(array_values($this->payments->intents)[0]->amountCents)->toBe(3580);
});

it('returns the original order when a request is repeated', function () {
    $first = placeOrder($this->menu->orderPayload())->assertCreated();
    $second = placeOrder($this->menu->orderPayload())->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and(count($this->payments->intents))->toBe(1)
        ->and($second->json('data.order.public_id'))->toBe($first->json('data.order.public_id'))
        ->and($second->json('data.payment.client_secret'))->toBe($first->json('data.payment.client_secret'));
});

it('treats a repeat as the same order even after a push token is added', function () {
    placeOrder($this->menu->orderPayload())->assertCreated();

    placeOrder($this->menu->orderPayload(['push_token' => 'ExponentPushToken[abc123]']))->assertOk();

    expect(Order::query()->count())->toBe(1);
});

it('refuses to reuse a key for a different order', function () {
    placeOrder($this->menu->orderPayload())->assertCreated();

    placeOrder($this->menu->orderPayload(['notes' => 'Something else']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This Idempotency-Key was already used for a different order. Send a new key for a new order.');

    expect(Order::query()->count())->toBe(1);
});

it('keeps keys separate per restaurant', function () {
    placeOrder($this->menu->orderPayload())->assertCreated();

    $other = MomoMenu::create();
    test()->url = "/api/v1/restaurants/{$other->restaurant->slug}/orders";

    placeOrder($other->orderPayload())->assertCreated();

    expect(Order::query()->count())->toBe(2);
});

it('asks for an Idempotency-Key', function () {
    $this->postJson($this->url, $this->menu->orderPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idempotency_key' => 'Send an Idempotency-Key header']);
});

it('asks the app to wait while the first request is still setting up payment', function () {
    placeOrder($this->menu->orderPayload());
    $order = Order::query()->sole();
    $order->forceFill(['stripe_payment_intent_id' => null])->save();

    $lock = Cache::lock("checkout:{$order->id}", 30);
    $lock->get();

    placeOrder($this->menu->orderPayload())
        ->assertConflict()
        ->assertJsonPath('message', 'This order is still being set up. Try again in a few seconds.');

    $lock->release();
});

it('lets the app retry when Stripe is down, without a second order', function () {
    $this->payments->failNext = true;

    placeOrder($this->menu->orderPayload())
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'We couldn’t reach the payment provider. Try again in a moment.');

    expect(Order::query()->sole()->stripe_payment_intent_id)->toBeNull();

    placeOrder($this->menu->orderPayload())->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->stripe_payment_intent_id)->not->toBeNull();
});

it('refuses ASAP orders while the restaurant is closed or paused', function (Closure $setUp, string $code) {
    $setUp($this->menu);

    placeOrder($this->menu->orderPayload())
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['scheduled_for']]);

    expect(Order::query()->count())->toBe(0)
        ->and($this->payments->createCalls)->toBe(0);
})->with([
    'closed' => [fn () => test()->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne')), 'closed'],
    'paused' => [fn (MomoMenu $menu) => $menu->restaurant->update(['is_accepting_orders' => false]), 'paused'],
]);

it('takes a scheduled order while closed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne'));

    placeOrder($this->menu->orderPayload(['scheduled_for' => '2026-10-05T18:30:00+11:00']))->assertCreated();

    expect(Order::query()->sole()->scheduled_for?->toIso8601ZuluString())->toBe('2026-10-05T07:30:00Z');
});

it('explains every problem with the cart', function () {
    placeOrder($this->menu->deliveryPayload(['postcode' => '3121', 'items' => [
        ['menu_item_id' => $this->menu->kothey->id, 'quantity' => 1, 'modifier_option_ids' => [$this->menu->chicken->id, $this->menu->mild->id]],
    ]]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kothey momo is sold out. Remove it to continue.')
        ->assertJsonPath('errors.postcode.0', fn (string $message) => str_starts_with($message, 'We don’t deliver to 3121.'));
});

it('asks for the customer’s details and a delivery address', function () {
    placeOrder($this->menu->deliveryPayload(['customer' => ['name' => '', 'phone' => '12', 'email' => 'nope'], 'delivery' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer.name', 'customer.phone', 'customer.email', 'delivery', 'delivery.line1', 'delivery.suburb']);
});

it('links a signed-in customer’s order to their account', function () {
    $customer = User::factory()->create();

    $this->withToken($customer->createToken('Phone')->plainTextToken);
    placeOrder($this->menu->orderPayload())->assertCreated();

    expect(Order::query()->sole()->user_id)->toBe($customer->id);
});

it('keeps a guest’s push token on the order', function () {
    placeOrder($this->menu->orderPayload(['push_token' => 'ExponentPushToken[guest-phone]']))->assertCreated();

    expect(Order::query()->sole()->push_token)->toBe('ExponentPushToken[guest-phone]');
});

it('slows down anyone placing orders in bulk', function () {
    foreach (range(1, 10) as $attempt) {
        placeOrder($this->menu->orderPayload(), "bulk-key-{$attempt}-abcdef")->assertCreated();
    }

    placeOrder($this->menu->orderPayload(), 'bulk-key-11-abcdef')->assertTooManyRequests();
});
