<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquarePayment;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSquareGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    /** @var FakePaymentGateway $stripe */
    $stripe = app(PaymentGateway::class);
    $this->stripe = $stripe;
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $this->square = $square;

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

/** A Stripe order waiting for payment, whose PaymentIntent Stripe has in that status; no webhook came. */
function orderWithIntent(MomoMenu $menu, FakePaymentGateway $stripe, string $intentStatus, string $intentId = 'pi_test_lost'): Order
{
    $order = Order::factory()->for($menu->restaurant)->create([
        'stripe_payment_intent_id' => $intentId,
        'subtotal_cents' => 2390,
        'total_cents' => 2390,
    ]);
    $stripe->intents[$intentId] = new PaymentIntent($intentId, "{$intentId}_secret", $intentStatus, 2390);

    return $order;
}

function orderPage(Order $order): TestResponse
{
    return test()->getJson("/api/v1/orders/{$order->public_id}?token={$order->tracking_token}");
}

it('sends a paid order to the kitchen while the customer waits, when the webhook was lost', function () {
    $order = orderWithIntent($this->menu, $this->stripe, 'succeeded');

    orderPage($order)->assertOk()
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.payment_status', 'paid');

    expect($order->refresh()->order_number)->not->toBeNull();
});

it('asks Stripe at most every 10 seconds while the order waits', function () {
    $order = orderWithIntent($this->menu, $this->stripe, 'requires_payment_method');

    orderPage($order)->assertJsonPath('data.status', 'pending_payment');
    orderPage($order)->assertJsonPath('data.status', 'pending_payment');

    expect($this->stripe->restaurants)->toHaveCount(1);

    $this->travel(11)->seconds();
    $this->stripe->intents['pi_test_lost'] = new PaymentIntent('pi_test_lost', 'pi_test_lost_secret', 'succeeded', 2390);

    orderPage($order)->assertJsonPath('data.status', 'placed');

    expect($this->stripe->restaurants)->toHaveCount(2);
});

it('keeps the order waiting when Stripe can’t be asked', function () {
    $order = orderWithIntent($this->menu, $this->stripe, 'succeeded');
    $this->stripe->failNext = true;

    orderPage($order)->assertOk()->assertJsonPath('data.status', 'pending_payment');
});

it('sends a paid Square order to the kitchen while the customer waits', function () {
    setUpSquare($this->menu->restaurant);
    $this->square->charges['earlier-key'] = new SquarePayment('sqpay_lost', 'COMPLETED', 2390);
    $order = Order::factory()->for($this->menu->restaurant)->square()->create([
        'square_payment_id' => 'sqpay_lost',
        'subtotal_cents' => 2390,
        'total_cents' => 2390,
    ]);

    orderPage($order)->assertJsonPath('data.status', 'placed');
});

it('checks with Stripe before cancelling an unpaid order that timed out', function () {
    $paid = orderWithIntent($this->menu, $this->stripe, 'succeeded', 'pi_test_paid');

    expect(app(OrderService::class)->expireUnpaid($paid)->status)->toBe(OrderStatus::Placed)
        ->and($this->stripe->cancelled)->toBe([]);

    $unpaid = orderWithIntent($this->menu, $this->stripe, 'requires_payment_method', 'pi_test_unpaid');

    expect(app(OrderService::class)->expireUnpaid($unpaid)->status)->toBe(OrderStatus::Cancelled)
        ->and($this->stripe->cancelled)->toBe(['pi_test_unpaid']);
});

it('waits to cancel while Stripe can’t be asked, for up to a day', function () {
    $order = orderWithIntent($this->menu, $this->stripe, 'succeeded');
    $this->stripe->failNext = true;

    expect(app(OrderService::class)->expireUnpaid($order)->status)->toBe(OrderStatus::PendingPayment);

    $order->forceFill(['created_at' => now()->subHours(25)])->save();
    $this->stripe->failNext = true;

    expect(app(OrderService::class)->expireUnpaid($order->refresh())->status)->toBe(OrderStatus::Cancelled);
});

describe('the back office', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->ownerOf($this->menu->restaurant)->create());
        useBackOffice($this->menu->restaurant);
    });

    it('checks the payment of an order still waiting for it', function () {
        $order = orderWithIntent($this->menu, $this->stripe, 'requires_payment_method');

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('checkPayment')
            ->assertNotified('Not paid');

        $this->stripe->intents['pi_test_lost'] = new PaymentIntent('pi_test_lost', 'pi_test_lost_secret', 'succeeded', 2390);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('checkPayment')
            ->assertNotified('Paid: it’s gone to the kitchen');

        expect($order->refresh()->status)->toBe(OrderStatus::Placed);
    });

    it('cancels an order still waiting for payment, refunding it if it was paid after all', function (string $intentStatus, PaymentStatus $paymentStatus) {
        $order = orderWithIntent($this->menu, $this->stripe, $intentStatus);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancelUnpaid', data: ['reason' => 'Payment stuck'])
            ->assertHasNoActionErrors()
            ->assertNotified('Order cancelled');

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment_status)->toBe($paymentStatus)
            ->and($this->stripe->refunds)->toHaveCount($paymentStatus === PaymentStatus::Refunded ? 1 : 0)
            ->and($this->stripe->cancelled)->toBe($paymentStatus === PaymentStatus::Refunded ? [] : ['pi_test_lost']);
    })->with([
        'not paid' => ['requires_payment_method', PaymentStatus::Unpaid],
        'paid after all' => ['succeeded', PaymentStatus::Refunded],
    ]);

    it('cancels nothing while Stripe can’t be asked', function () {
        $order = orderWithIntent($this->menu, $this->stripe, 'succeeded');
        $this->stripe->failNext = true;

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancelUnpaid', data: ['reason' => 'Payment stuck'])
            ->assertNotified('Stripe couldn’t be asked, so nothing was cancelled');

        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });
});

it('logs a refused webhook, so a wrong signing secret shows up', function () {
    Log::spy();

    $this->call('POST', "/api/v1/stripe/webhook/{$this->menu->restaurant->slug}", server: [
        'HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged',
        'CONTENT_TYPE' => 'application/json',
    ], content: '{"id":"evt_forged"}')->assertBadRequest();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'A Stripe webhook was refused.'
        && $context['restaurant'] === $this->menu->restaurant->slug);
});
