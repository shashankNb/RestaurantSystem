<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderUpdated;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakePaymentGateway;
use Tests\Support\MomoMenu;
use Tests\Support\StripeEvents;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function unpaidOrder(MomoMenu $menu, array $payload = [], string $key = 'webhook-key-0001'): Order
{
    test()->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders", $menu->orderPayload($payload))
        ->assertCreated();

    return Order::query()->latest('id')->firstOrFail();
}

it('places the order when Stripe confirms the payment', function () {
    Mail::fake();
    Event::fake([OrderUpdated::class]);
    $order = unpaidOrder($this->menu, ['promo_code' => 'MOMO10']);

    StripeEvents::send(StripeEvents::succeeded($order))->assertOk()->assertJsonPath('received', true);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Placed)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->display_number)->toBe('001')
        ->and($order->business_date?->toDateString())->toBe('2026-10-05')
        ->and($order->placed_at?->toIso8601ZuluString())->toBe('2026-10-05T07:00:00Z')
        ->and($this->menu->momo10->refresh()->uses_count)->toBe(1)
        ->and(StripeEvent::query()->sole()->processed_at)->not->toBeNull();

    Mail::assertQueued(OrderPlaced::class, fn (OrderPlaced $mail) => $mail->hasTo('sam@example.com') && $mail->order->is($order));
    Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $event) => $event->order->is($order) && $event->order->status === OrderStatus::Placed);
});

it('retries on the queue when processing an event fails', function () {
    Exceptions::fake();
    $order = unpaidOrder($this->menu);
    // Queue (rather than run) the retry, and make processing fail.
    config(['queue.default' => 'database']);
    Order::updating(fn () => throw new RuntimeException('The database went away.'));

    StripeEvents::send(StripeEvents::succeeded($order))->assertOk();

    Exceptions::assertReported(RuntimeException::class);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and((string) DB::table('jobs')->value('payload'))->toContain('ProcessStripeEvent')
        ->and(StripeEvent::query()->sole()->processed_at)->toBeNull()
        ->and($order->fresh()?->status)->toBe(OrderStatus::PendingPayment);
});

it('rejects a request that isn’t signed by Stripe', function (Closure $send) {
    $order = unpaidOrder($this->menu);

    $send(StripeEvents::succeeded($order))->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(StripeEvent::query()->count())->toBe(0);
})->with([
    'wrong secret' => [fn (array $event) => StripeEvents::send($event, secret: 'whsec_someone_else')],
    'signed too long ago' => [fn (array $event) => StripeEvents::send($event, signedAt: time() - 600)],
    'no signature' => [fn (array $event) => test()->postJson('/api/v1/stripe/webhook', $event)],
    'body changed after signing' => [function (array $event) {
        $body = (string) json_encode($event);
        $signedAt = time();
        $signature = hash_hmac('sha256', "{$signedAt}.{$body}", StripeEvents::SECRET);
        $event['data']['object']['amount_received'] = 1;

        return test()->call('POST', '/api/v1/stripe/webhook', server: [
            'HTTP_STRIPE_SIGNATURE' => "t={$signedAt},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode($event));
    }],
]);

it('processes a replayed event only once', function () {
    Mail::fake();
    $order = unpaidOrder($this->menu, ['promo_code' => 'MOMO10']);
    $event = StripeEvents::succeeded($order, 'evt_replayed');

    StripeEvents::send($event)->assertOk();
    StripeEvents::send($event)->assertOk()->assertJsonPath('duplicate', true);

    expect(StripeEvent::query()->count())->toBe(1)
        ->and($order->statusEvents()->where('to_status', OrderStatus::Placed)->count())->toBe(1)
        ->and($this->menu->momo10->refresh()->uses_count)->toBe(1);

    Mail::assertQueued(OrderPlaced::class, 1);
});

it('ignores a second event for a payment it already has', function () {
    $order = unpaidOrder($this->menu);

    StripeEvents::send(StripeEvents::succeeded($order, 'evt_first'))->assertOk();
    StripeEvents::send(StripeEvents::succeeded($order, 'evt_second'))->assertOk();

    expect($order->refresh()->display_number)->toBe('001')
        ->and($order->statusEvents()->where('to_status', OrderStatus::Placed)->count())->toBe(1);
});

it('numbers each restaurant’s paid orders in order, restarting each business day', function () {
    $first = unpaidOrder($this->menu, key: 'number-key-0001');
    $unpaid = unpaidOrder($this->menu, key: 'number-key-0002');
    $second = unpaidOrder($this->menu, key: 'number-key-0003');
    $elsewhere = MomoMenu::create();
    $theirs = unpaidOrder($elsewhere, key: 'number-key-0004');

    foreach ([$first, $second, $theirs] as $order) {
        StripeEvents::send(StripeEvents::succeeded($order))->assertOk();
    }

    // Tomorrow at 1 am still belongs to today's business day; at 5 am a new day begins.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 01:00', 'Australia/Melbourne'));
    $lateNight = unpaidOrder($this->menu, ['scheduled_for' => '2026-10-06T17:00:00+11:00'], 'number-key-0005');
    StripeEvents::send(StripeEvents::succeeded($lateNight))->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00', 'Australia/Melbourne'));
    $nextDay = unpaidOrder($this->menu, ['scheduled_for' => '2026-10-06T17:00:00+11:00'], 'number-key-0006');
    StripeEvents::send(StripeEvents::succeeded($nextDay))->assertOk();

    expect($first->refresh()->display_number)->toBe('001')
        ->and($second->refresh()->display_number)->toBe('002')
        ->and($unpaid->refresh()->display_number)->toBeNull()
        ->and($theirs->refresh()->display_number)->toBe('001')
        ->and($lateNight->refresh()->display_number)->toBe('003')
        ->and($lateNight->business_date?->toDateString())->toBe('2026-10-05')
        ->and($nextDay->refresh()->display_number)->toBe('001')
        ->and($nextDay->business_date?->toDateString())->toBe('2026-10-06');
});

it('notes a failed payment and keeps the order open for another try', function () {
    $order = unpaidOrder($this->menu);

    StripeEvents::send(StripeEvents::failed($order))->assertOk();
    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Failed)
        ->and($order->status)->toBe(OrderStatus::PendingPayment);

    StripeEvents::send(StripeEvents::succeeded($order))->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::Placed);
});

it('refunds a payment that arrives after the checkout was cancelled', function () {
    $order = unpaidOrder($this->menu);
    $this->travel(31)->minutes();
    $this->artisan('orders:cancel-unpaid')->assertSuccessful();

    StripeEvents::send(StripeEvents::succeeded($order))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($this->payments->refunds)->toHaveCount(1)
        ->and($this->payments->refunds[0]['amount'])->toBe($order->total_cents);
});

it('won’t place an order whose payment doesn’t match its total', function () {
    $order = unpaidOrder($this->menu);

    StripeEvents::send(StripeEvents::succeeded($order, amount: 100))->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid);
});

it('acknowledges events it doesn’t use without storing them', function () {
    $event = ['id' => 'evt_other', 'object' => 'event', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']]];

    StripeEvents::send($event)->assertOk()->assertJsonPath('received', true);

    expect(StripeEvent::query()->count())->toBe(0);
});
