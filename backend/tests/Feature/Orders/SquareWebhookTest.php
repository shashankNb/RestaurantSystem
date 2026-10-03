<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Enums\SquareEnvironment;
use App\Http\Controllers\Api\SquareWebhookController;
use App\Models\Order;
use App\Models\SquareEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    connectSquare($this->menu->restaurant);
    $this->order = Order::factory()->for($this->menu->restaurant)->square()->create();

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

/** Sends an event as Square does: signed with the sandbox subscription's key and URL. */
function squareEvent(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $url = SquareWebhookController::notificationUrl(SquareEnvironment::Sandbox);
    $signature ??= base64_encode(hash_hmac('sha256', $url.$body, 'test-square-signature-key', true));

    return test()->call('POST', '/api/v1/square/webhook/sandbox', server: [
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}

function squarePaymentEvent(Order $order, array $payment = [], string $merchantId = 'MSQUARE123'): array
{
    return [
        'merchant_id' => $merchantId,
        'type' => 'payment.updated',
        'event_id' => (string) Str::uuid(),
        'created_at' => now()->toIso8601ZuluString(),
        'data' => ['type' => 'payment', 'id' => 'sqpay_late', 'object' => ['payment' => [
            'id' => 'sqpay_late',
            'status' => 'COMPLETED',
            'amount_money' => ['amount' => $order->total_cents, 'currency' => 'AUD'],
            'location_id' => 'LMAIN',
            'reference_id' => $order->public_id,
            ...$payment,
        ]]],
    ];
}

it('places an order whose payment Square finished after the customer left', function () {
    squareEvent(squarePaymentEvent($this->order))->assertOk()->assertJson(['received' => true]);

    $order = $this->order->refresh();

    expect($order->status)->toBe(OrderStatus::Placed)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->square_payment_id)->toBe('sqpay_late')
        ->and(SquareEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('acts on each event once', function () {
    $event = squarePaymentEvent($this->order);

    squareEvent($event)->assertOk();
    squareEvent($event)->assertOk()->assertJson(['duplicate' => true]);

    expect(SquareEvent::query()->count())->toBe(1)
        ->and($this->order->statusEvents()->where('to_status', OrderStatus::Placed)->count())->toBe(1);
});

it('refuses an event Square didn’t sign', function () {
    squareEvent(squarePaymentEvent($this->order), signature: base64_encode('forged'))->assertBadRequest();

    expect($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(SquareEvent::query()->count())->toBe(0);
});

it('ignores the restaurant’s other Square payments, and other Square accounts', function (array $payment, string $merchantId) {
    squareEvent(squarePaymentEvent($this->order, $payment, $merchantId))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(SquareEvent::query()->count())->toBe(0);
})->with([
    'an in-person sale' => [['id' => 'sqpay_counter', 'reference_id' => null], 'MSQUARE123'],
    'not finished' => [['status' => 'APPROVED'], 'MSQUARE123'],
    'another account' => [[], 'MSOMEONEELSE'],
]);

it('forgets a Square account disconnected on Square’s side, and goes back to Stripe', function () {
    squareEvent([
        'merchant_id' => 'MSQUARE123',
        'type' => 'oauth.authorization.revoked',
        'event_id' => (string) Str::uuid(),
        'data' => ['type' => 'revocation', 'object' => ['revocation' => ['revoker_type' => 'MERCHANT']]],
    ])->assertOk();

    $restaurant = $this->menu->restaurant->refresh();

    expect($restaurant->squareConnected())->toBeFalse()
        ->and($restaurant->square_access_token)->toBeNull()
        ->and($restaurant->payment_processor)->toBe(PaymentProcessor::Stripe);
});

it('reports a refund Square couldn’t make', function () {
    Log::spy();
    $this->order->forceFill(['square_refund_id' => 'sqref_1'])->save();

    squareEvent([
        'merchant_id' => 'MSQUARE123',
        'type' => 'refund.updated',
        'event_id' => (string) Str::uuid(),
        'data' => ['type' => 'refund', 'object' => ['refund' => ['id' => 'sqref_1', 'payment_id' => 'sqpay_1', 'status' => 'FAILED']]],
    ])->assertOk();

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'Square couldn’t make a refund'));
});
