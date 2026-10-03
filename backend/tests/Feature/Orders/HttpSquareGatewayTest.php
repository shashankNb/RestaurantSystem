<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\HttpSquareGateway;
use App\Payments\Square\SquareConnectionLost;
use App\Payments\Square\SquareLocation;
use App\Payments\Square\SquarePayment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->restaurant = setUpSquare(Restaurant::factory()->create());
    $this->order = Order::factory()->for($this->restaurant)->square()->create(['total_cents' => 2390, 'customer_email' => 'asha@example.com']);
    $this->gateway = new HttpSquareGateway;
});

const SQUARE_SANDBOX = 'https://connect.squareupsandbox.com';

it('charges the order’s total at the restaurant’s location, as Square expects', function () {
    Http::fake([SQUARE_SANDBOX.'/v2/payments' => Http::response(['payment' => ['id' => 'sqpay_1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 2390, 'currency' => 'AUD']]])]);

    $payment = $this->gateway->charge($this->order->load('restaurant'), 'cnon:card-nonce-ok', 'verf:1', str_repeat('k', 40));

    expect($payment)->toEqual(new SquarePayment('sqpay_1', 'COMPLETED', 2390));

    Http::assertSent(fn (Request $request): bool => $request->url() === SQUARE_SANDBOX.'/v2/payments'
        && $request->hasHeader('Authorization', 'Bearer EAAA-token')
        && $request->hasHeader('Square-Version', '2026-09-16')
        && $request['source_id'] === 'cnon:card-nonce-ok'
        && $request['idempotency_key'] === str_repeat('k', 40)
        && $request['amount_money'] === ['amount' => 2390, 'currency' => 'AUD']
        && $request['location_id'] === 'LMAIN'
        && $request['autocomplete'] === true
        && $request['reference_id'] === $this->order->public_id
        && $request['buyer_email_address'] === 'asha@example.com'
        && $request['verification_token'] === 'verf:1');
});

it('says what to do when Square declines the card', function (string $code, string $message) {
    Http::fake([SQUARE_SANDBOX.'/v2/payments' => Http::response(['errors' => [['category' => 'PAYMENT_METHOD_ERROR', 'code' => $code, 'detail' => 'Authorization error']]], 402)]);

    expect(fn () => $this->gateway->charge($this->order->load('restaurant'), 'cnon:card-nonce-declined', null, 'key-1'))
        ->toThrow(PaymentDeclined::class, $message);
})->with([
    'declined' => ['GENERIC_DECLINE', 'Your card was declined. Try another card, or contact your bank.'],
    'no money' => ['INSUFFICIENT_FUNDS', 'Your card was declined: there isn’t enough money in the account. Try another card.'],
    'wrong CVC' => ['CVV_FAILURE', 'The card’s security code didn’t match. Check it and try again.'],
]);

it('tries again when Square has a fault, and says it’s unavailable if it lasts', function () {
    Http::fakeSequence(SQUARE_SANDBOX.'/v2/payments')
        ->push(['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR']]], 500)
        ->push(['payment' => ['id' => 'sqpay_2', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 2390]]]);

    expect($this->gateway->charge($this->order->load('restaurant'), 'cnon:card-nonce-ok', null, 'key-1')->id)->toBe('sqpay_2');

    Http::fake([SQUARE_SANDBOX.'/v2/refunds' => Http::response(['errors' => [['category' => 'API_ERROR', 'code' => 'SERVICE_UNAVAILABLE']]], 503)]);

    expect(fn () => $this->gateway->refund($this->order->forceFill(['square_payment_id' => 'sqpay_2']), 'refund-1'))
        ->toThrow(PaymentsUnavailable::class);
});

it('says Square didn’t accept the access token', function () {
    Http::fake([SQUARE_SANDBOX.'/v2/locations' => Http::response(['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED']]], 401)]);

    expect(fn () => $this->gateway->locations($this->restaurant))->toThrow(SquareConnectionLost::class);
});

it('refunds the order’s payment in full', function () {
    Http::fake([SQUARE_SANDBOX.'/v2/refunds' => Http::response(['refund' => ['id' => 'sqref_1', 'status' => 'PENDING']])]);
    $this->order->forceFill(['square_payment_id' => 'sqpay_1'])->save();

    expect($this->gateway->refund($this->order->load('restaurant'), "refund-{$this->order->public_id}"))->toBe('sqref_1');

    Http::assertSent(fn (Request $request): bool => $request['payment_id'] === 'sqpay_1'
        && $request['idempotency_key'] === "refund-{$this->order->public_id}"
        && $request['amount_money'] === ['amount' => 2390, 'currency' => 'AUD']);
});

it('reads the account’s name and locations, in the environment of its application ID', function () {
    Http::fake([
        SQUARE_SANDBOX.'/v2/merchants/me' => Http::response(['merchant' => ['id' => 'MSQUARE123', 'business_name' => 'Momo House']]),
        SQUARE_SANDBOX.'/v2/locations' => Http::response(['locations' => [
            ['id' => 'LMAIN', 'name' => 'Main Street', 'currency' => 'AUD', 'status' => 'ACTIVE'],
            ['id' => 'LOLD', 'name' => 'Old shop', 'currency' => 'AUD', 'status' => 'INACTIVE'],
        ]]),
    ]);

    expect($this->gateway->merchantName($this->restaurant))->toBe('Momo House')
        ->and($this->gateway->locations($this->restaurant))->toEqual([
            new SquareLocation('LMAIN', 'Main Street', 'AUD', true),
            new SquareLocation('LOLD', 'Old shop', 'AUD', false),
        ]);

    // Production credentials go to Square's production host.
    Http::fake(['https://connect.squareup.com/v2/merchants/me' => Http::response(['merchant' => ['business_name' => 'Momo House Live']])]);
    $this->restaurant->forceFill(['square_application_id' => 'sq0idp-live-app'])->save();

    expect($this->gateway->merchantName($this->restaurant))->toBe('Momo House Live');
});

it('registers the website for Apple Pay, and says why Square refused it', function () {
    Http::fake([SQUARE_SANDBOX.'/v2/apple-pay/domains' => Http::sequence()
        ->push(['status' => 'VERIFIED'])
        ->push(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'BAD_REQUEST', 'detail' => 'The domain verification file was not found.']]], 400)]);

    expect($this->gateway->registerApplePayDomain($this->restaurant, 'order.momohouse.com.au')->domainReady)->toBeTrue();

    $refused = $this->gateway->registerApplePayDomain($this->restaurant, 'order.momohouse.com.au');

    expect($refused->domainReady)->toBeFalse()
        ->and($refused->domainProblem)->toBe('The domain verification file was not found.');

    // Without a public website there's nothing to register.
    expect($this->gateway->registerApplePayDomain($this->restaurant, null)->domain)->toBeNull();
    Http::assertSentCount(2);
});
