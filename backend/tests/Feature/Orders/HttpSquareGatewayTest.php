<?php

use App\Enums\SquareEnvironment;
use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentDeclined;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\HttpSquareGateway;
use App\Payments\Square\SquareApp;
use App\Payments\Square\SquareConnectionLost;
use App\Payments\Square\SquareLocation;
use App\Payments\Square\SquarePayment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->restaurant = connectSquare(Restaurant::factory()->create());
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

it('says the connection is lost when Square no longer accepts the token', function () {
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

it('connects with the code from Square, and renews and revokes the tokens', function () {
    Http::fake([
        SQUARE_SANDBOX.'/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'EAAA-new', 'refresh_token' => 'EQAA-new', 'expires_at' => '2026-11-02T00:00:00Z', 'merchant_id' => 'MSQUARE123'])
            ->push(['access_token' => 'EAAA-renewed', 'refresh_token' => 'EQAA-new', 'expires_at' => '2026-11-09T00:00:00Z', 'merchant_id' => 'MSQUARE123'])
            ->push(['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED']]], 401),
        SQUARE_SANDBOX.'/oauth2/revoke' => Http::response(['success' => true]),
    ]);
    $app = SquareApp::for(SquareEnvironment::Sandbox);

    $connection = $this->gateway->exchangeCode($app, 'sq0cgp-code');

    expect($connection->accessToken)->toBe('EAAA-new')
        ->and($connection->merchantId)->toBe('MSQUARE123')
        ->and($connection->expiresAt->toIso8601ZuluString())->toBe('2026-11-02T00:00:00Z')
        ->and($this->gateway->refresh($this->restaurant)->accessToken)->toBe('EAAA-renewed')
        ->and(fn () => $this->gateway->refresh($this->restaurant))->toThrow(SquareConnectionLost::class);

    $this->gateway->revoke($this->restaurant);

    Http::assertSent(fn (Request $request): bool => $request->url() === SQUARE_SANDBOX.'/oauth2/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_id'] === 'sandbox-sq0idb-test-app'
        && $request['client_secret'] === 'sandbox-sq0csb-test-secret'
        && $request['code'] === 'sq0cgp-code');
    Http::assertSent(fn (Request $request): bool => $request->url() === SQUARE_SANDBOX.'/oauth2/revoke'
        && $request->hasHeader('Authorization', 'Client sandbox-sq0csb-test-secret')
        && $request['access_token'] === 'EAAA-token');
});

it('reads the account’s name and locations', function () {
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
