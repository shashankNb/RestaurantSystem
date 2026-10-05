<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\SquareWebhookController;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\SquareEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    setUpSquare($this->menu->restaurant);
    $this->order = Order::factory()->for($this->menu->restaurant)->square()->create();

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

/** Sends an event as the restaurant's Square application does: signed with its key and URL. */
function squareEvent(Restaurant $restaurant, array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $url = SquareWebhookController::notificationUrl($restaurant);
    $signature ??= base64_encode(hash_hmac('sha256', $url.$body, 'test-square-signature-key', true));

    return test()->call('POST', "/api/v1/square/webhook/{$restaurant->slug}", server: [
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}

function squarePaymentEvent(Order $order, array $payment = []): array
{
    return [
        'merchant_id' => 'MSQUARE123',
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
    squareEvent($this->menu->restaurant, squarePaymentEvent($this->order))->assertOk()->assertJson(['received' => true]);

    $order = $this->order->refresh();

    expect($order->status)->toBe(OrderStatus::Placed)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->square_payment_id)->toBe('sqpay_late')
        ->and(SquareEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('acts on each event once', function () {
    $event = squarePaymentEvent($this->order);

    squareEvent($this->menu->restaurant, $event)->assertOk();
    squareEvent($this->menu->restaurant, $event)->assertOk()->assertJson(['duplicate' => true]);

    expect(SquareEvent::query()->count())->toBe(1)
        ->and($this->order->statusEvents()->where('to_status', OrderStatus::Placed)->count())->toBe(1);
});

it('refuses an event the restaurant’s Square application didn’t sign', function () {
    $response = squareEvent($this->menu->restaurant, squarePaymentEvent($this->order), signature: base64_encode('forged'))->assertBadRequest();

    // Square signs the URL too: the answer says which one it must be.
    expect($response->json('message'))->toContain('isn’t exactly '.SquareWebhookController::notificationUrl($this->menu->restaurant));

    // Without a signature key, nothing is accepted.
    $this->menu->restaurant->forceFill(['square_webhook_signature_key' => null])->save();
    squareEvent($this->menu->restaurant, squarePaymentEvent($this->order))->assertBadRequest();

    expect($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(SquareEvent::query()->count())->toBe(0);
});

it('only touches the orders of the restaurant whose webhook it is', function () {
    $other = setUpSquare(MomoMenu::create()->restaurant);

    squareEvent($other, squarePaymentEvent($this->order))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(SquareEvent::query()->count())->toBe(0);
});

it('ignores the restaurant’s other Square payments', function (array $payment) {
    squareEvent($this->menu->restaurant, squarePaymentEvent($this->order, $payment))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(SquareEvent::query()->count())->toBe(0);
})->with([
    'an in-person sale' => [['id' => 'sqpay_counter', 'reference_id' => null]],
    'not finished' => [['status' => 'APPROVED']],
]);

it('reports a refund Square couldn’t make', function () {
    Log::spy();
    $this->order->forceFill(['square_refund_id' => 'sqref_1'])->save();

    squareEvent($this->menu->restaurant, [
        'merchant_id' => 'MSQUARE123',
        'type' => 'refund.updated',
        'event_id' => (string) Str::uuid(),
        'data' => ['type' => 'refund', 'object' => ['refund' => ['id' => 'sqref_1', 'payment_id' => 'sqpay_1', 'status' => 'FAILED']]],
    ])->assertOk();

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'Square couldn’t make a refund'));
});
