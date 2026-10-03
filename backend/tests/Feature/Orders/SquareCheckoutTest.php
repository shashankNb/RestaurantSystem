<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Payments\Square\SquareGateway;
use App\Services\OrderService;
use App\Services\SquareCheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSquareGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    setUpSquare($this->menu->restaurant);
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $this->square = $square;
    /** @var FakePaymentGateway $stripe */
    $stripe = app(PaymentGateway::class);
    $this->stripe = $stripe;

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function squareCheckout(MomoMenu $menu, string $key = 'square-order-0001'): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders", $menu->orderPayload());
}

function paySquare(Order $order, array $body = [], string $key = 'square-pay-0001'): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)->postJson("/api/v1/orders/{$order->public_id}/square-payment", [
        'tracking_token' => $order->tracking_token,
        'source_id' => 'cnon:card-nonce-ok',
        ...$body,
    ]);
}

it('creates a Square order with nothing to set up first', function () {
    $response = squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();

    expect($order->payment_processor)->toBe(PaymentProcessor::Square)
        ->and($order->stripe_payment_intent_id)->toBeNull()
        ->and($this->stripe->createCalls)->toBe(0)
        ->and($response->json('data.payment'))->toBe(['processor' => 'square', 'amount_cents' => $order->total_cents, 'currency' => 'aud']);
});

it('places the order as soon as Square takes the payment', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();

    paySquare($order, ['verification_token' => 'verf:checked'])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'placed')
        ->assertJsonPath('data.order.payment_status', 'paid');

    $order->refresh();
    $charge = $this->square->chargeRequests[0];

    expect($order->status)->toBe(OrderStatus::Placed)
        ->and($order->order_number)->not->toBeNull()
        ->and($order->square_payment_id)->toBe($this->square->charges[$charge['key']]->id)
        ->and($charge)->toMatchArray(['order' => $order->public_id, 'source' => 'cnon:card-nonce-ok', 'verification' => 'verf:checked'])
        // Square allows 45 characters.
        ->and(strlen($charge['key']))->toBeLessThanOrEqual(45);
});

it('charges a retried request once, and a paid order never again', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();

    paySquare($order)->assertOk();
    paySquare($order)->assertOk();
    paySquare($order, key: 'square-pay-0002')->assertOk()->assertJsonPath('data.order.status', 'placed');

    expect($this->square->chargeRequests)->toHaveCount(1);
});

it('says why a card was declined, and takes another card for the same order', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    $this->square->declineNext = 'INSUFFICIENT_FUNDS';

    paySquare($order)
        ->assertStatus(402)
        ->assertJsonPath('message', 'Your card was declined: there isn’t enough money in the account. Try another card.');

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->payment_status)->toBe(PaymentStatus::Failed);

    paySquare($order, ['source_id' => 'cnon:another-card'], key: 'square-pay-0002')->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Placed)
        ->and($this->square->chargeRequests)->toHaveCount(2)
        ->and($this->square->chargeRequests[0]['key'])->not->toBe($this->square->chargeRequests[1]['key']);
});

it('charges nothing once the order has timed out', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    app(OrderService::class)->expireUnpaid($order);

    paySquare($order)
        ->assertConflict()
        ->assertJsonPath('message', 'This order timed out before it was paid, and nothing was charged. Place your order again.');

    expect($this->square->chargeRequests)->toBe([]);
});

it('doesn’t time an order out while its payment is going through', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    $lock = Cache::lock(SquareCheckoutService::lockName($order), 120);
    $lock->get();

    expect(app(OrderService::class)->expireUnpaid($order)->status)->toBe(OrderStatus::PendingPayment);

    $lock->release();

    expect(app(OrderService::class)->expireUnpaid($order)->status)->toBe(OrderStatus::Cancelled)
        ->and($this->stripe->cancelled)->toBe([]);
});

it('only pays the customer’s own Square order', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();

    paySquare($order, ['tracking_token' => 'not-its-token'])->assertNotFound();

    $this->flushHeaders()
        ->postJson("/api/v1/orders/{$order->public_id}/square-payment", ['tracking_token' => $order->tracking_token, 'source_id' => 'cnon:card-nonce-ok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    $stripeOrder = Order::factory()->for($this->menu->restaurant)->create();

    paySquare($stripeOrder)->assertConflict();

    expect($this->square->chargeRequests)->toBe([]);
});

it('says when Square can’t be reached, and the order can be paid in a moment', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    $this->square->failNext = true;

    paySquare($order)->assertServiceUnavailable();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    paySquare($order)->assertOk();
});

it('waits for Square’s webhook when the payment isn’t finished yet', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    $this->square->paymentStatus = 'PENDING';

    paySquare($order)->assertAccepted()->assertJsonPath('data.order.status', 'pending_payment');

    expect($order->refresh()->square_payment_id)->not->toBeNull();
});

it('refunds a Square order through Square, even after switching to Stripe', function () {
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    paySquare($order)->assertOk();

    $this->menu->restaurant->forceFill(['payment_processor' => PaymentProcessor::Stripe])->save();
    app(OrderService::class)->reject($order->refresh(), 'Kitchen closed early', null);

    $order->refresh();

    expect($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->square_refund_id)->toBe($this->square->refunds["refund-{$order->public_id}"])
        ->and($this->stripe->refunds)->toBe([]);
});

it('keeps a Stripe order with Stripe after switching to Square', function () {
    $this->menu->restaurant->forceFill(['payment_processor' => PaymentProcessor::Stripe])->save();
    squareCheckout($this->menu)->assertCreated();
    $order = Order::query()->sole();
    setUpSquare($this->menu->restaurant);

    // Stripe's webhook confirms the payment after the switch.
    app(OrderService::class)->markPaid($order, (string) $order->stripe_payment_intent_id, $order->total_cents);
    app(OrderService::class)->reject($order->refresh(), 'Kitchen closed early', null);

    expect($order->refresh()->payment_processor)->toBe(PaymentProcessor::Stripe)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($this->stripe->refunds)->toHaveCount(1)
        ->and($this->square->refunds)->toBe([]);
});

it('gives the apps the Square settings, and never a token', function () {
    $response = $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")->assertOk();

    expect($response->json('data.payments'))->toBe([
        'processor' => 'square',
        'stripe_publishable_key' => null,
        'square' => ['application_id' => 'sandbox-sq0idb-test-app', 'location_id' => 'LMAIN', 'environment' => 'sandbox'],
    ])
        ->and($response->getContent())->not->toContain('EAAA-token')
        ->and($response->getContent())->not->toContain('EQAA-token');
});

it('starts no Square order until a location is chosen', function () {
    $this->menu->restaurant->forceFill(['square_location_id' => null])->save();

    squareCheckout($this->menu)->assertServiceUnavailable();

    expect(Order::query()->count())->toBe(0);

    $this->getJson("/api/v1/restaurants/{$this->menu->restaurant->slug}")->assertJsonPath('data.payments.square', null);
});
