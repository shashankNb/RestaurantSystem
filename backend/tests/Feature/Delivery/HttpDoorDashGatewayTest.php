<?php

use App\Delivery\CourierUnavailable;
use App\Delivery\DoorDash\DoorDashJwt;
use App\Delivery\DoorDash\DoorDashKeyRejected;
use App\Delivery\DoorDash\DoorDashRefused;
use App\Delivery\DoorDash\HttpDoorDashGateway;
use App\Models\Restaurant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->restaurant = setUpDoorDash(Restaurant::factory()->create());
    $this->gateway = new HttpDoorDashGateway;
    Sleep::fake();
});

/**
 * A delivery as DoorDash's Drive API describes it.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function driveDelivery(array $fields = []): array
{
    return [
        'external_delivery_id' => 'order-1',
        'delivery_status' => 'created',
        'fee' => 975,
        'tax' => 98,
        'currency' => 'AUD',
        'tracking_url' => 'https://www.doordash.com/drive/portal/track/abc',
        'support_reference' => 1234567,
        'dasher_name' => null,
        'pickup_time_estimated' => '2026-10-05T07:20:00Z',
        'dropoff_time_estimated' => '2026-10-05T07:45:00Z',
        ...$fields,
    ];
}

it('signs a JWT the way DoorDash checks it', function () {
    // Worked out independently, from DoorDash's JWT guide, for these inputs.
    expect(DoorDashJwt::make('dev-1234', 'key-5678', 'c2lnbmluZy1zZWNyZXQtZm9yLXRlc3Rz', 1759647600))->toBe(
        'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCIsImRkLXZlciI6IkRELUpXVC1WMSJ9'
        .'.eyJhdWQiOiJkb29yZGFzaCIsImlzcyI6ImRldi0xMjM0Iiwia2lkIjoia2V5LTU2NzgiLCJleHAiOjE3NTk2NDc5MDAsImlhdCI6MTc1OTY0NzYwMH0'
        .'.vbdiY07_S5pewF1wsIVSLVg8fwfI88Qqmwqt0KySxWU',
    );
});

it('quotes, books, looks up and cancels deliveries, signed with the restaurant’s own key', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(driveDelivery())]);

    $quote = $this->gateway->quote($this->restaurant, ['external_delivery_id' => 'order-1', 'dropoff_address' => '12 Southbank Boulevard, Southbank VIC 3006, Australia']);
    $this->gateway->acceptQuote($this->restaurant, 'order-1');
    $this->gateway->delivery($this->restaurant, 'order-1');
    $this->gateway->cancel($this->restaurant, 'order-1');

    expect($quote->status)->toBe('created')
        // DoorDash's charge includes the tax it lists separately.
        ->and($quote->feeCents)->toBe(975 + 98)
        ->and($quote->supportReference)->toBe('1234567')
        ->and($quote->trackingUrl)->toBe('https://www.doordash.com/drive/portal/track/abc')
        ->and($quote->dropoffEstimate?->toIso8601ZuluString())->toBe('2026-10-05T07:45:00Z');

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($sent->map(fn (Request $request): string => "{$request->method()} {$request->url()}")->all())->toBe([
        'POST https://openapi.doordash.com/drive/v2/quotes',
        'POST https://openapi.doordash.com/drive/v2/quotes/order-1/accept',
        'GET https://openapi.doordash.com/drive/v2/deliveries/order-1',
        'PUT https://openapi.doordash.com/drive/v2/deliveries/order-1/cancel',
    ])
        ->and($sent[0]->data())->toBe(['external_delivery_id' => 'order-1', 'dropoff_address' => '12 Southbank Boulevard, Southbank VIC 3006, Australia'])
        ->and($sent[1]->body())->toBe('{}');

    [$header, $claims] = array_map(
        fn (string $part): array => (array) json_decode((string) base64_decode(strtr($part, '-_', '+/')), true),
        array_slice(explode('.', substr((string) $sent[0]->header('Authorization')[0], strlen('Bearer '))), 0, 2),
    );

    expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT', 'dd-ver' => 'DD-JWT-V1'])
        ->and($claims)->toMatchArray(['aud' => 'doordash', 'iss' => 'dev-1234', 'kid' => 'key-5678'])
        ->and($claims['exp'] - $claims['iat'])->toBe(300);
});

it('passes on DoorDash refusing a delivery, with its reason', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response([
        'code' => 'validation_error',
        'message' => 'Validation Failed',
        'field_errors' => [['field' => 'dropoff_address', 'error' => 'Address is outside of the coverage area']],
    ], 422)]);

    try {
        $this->gateway->quote($this->restaurant, ['external_delivery_id' => 'order-1']);
        $this->fail('DoorDash refused it.');
    } catch (DoorDashRefused $refused) {
        expect($refused->status)->toBe(422)
            ->and($refused->field)->toBe('dropoff_address')
            ->and($refused->fieldErrors)->toBe(['dropoff_address' => 'Address is outside of the coverage area'])
            ->and($refused->getMessage())->toBe('dropoff_address: Address is outside of the coverage area')
            ->and($refused->isAboutDropoff())->toBeTrue();
    }
});

it('tells a refusal about the address from one about the restaurant or the request', function (int $status, array $body, string $reason, bool $aboutDropoff) {
    Http::fake(['openapi.doordash.com/*' => Http::response($body, $status)]);

    try {
        $this->gateway->quote($this->restaurant, ['external_delivery_id' => 'order-1']);
        $this->fail('DoorDash refused it.');
    } catch (DoorDashRefused $refused) {
        expect($refused->getMessage())->toBe($reason)
            ->and($refused->isAboutDropoff())->toBe($aboutDropoff);
    }
})->with([
    'too far, naming no field' => [422, ['code' => 'unprocessable_entity', 'message' => 'Allowed distance between addresses exceeded'], 'Allowed distance between addresses exceeded', true],
    'the customer’s phone' => [400, ['message' => 'Validation Failed', 'field_errors' => [['field' => 'dropoff_phone_number', 'error' => 'Invalid phone number']]], 'dropoff_phone_number: Invalid phone number', true],
    'the restaurant’s phone' => [400, ['message' => 'Validation Failed', 'field_errors' => [['field' => 'pickup_phone_number', 'error' => 'Invalid phone number']]], 'pickup_phone_number: Invalid phone number', false],
    'both addresses' => [
        400,
        ['message' => 'Validation Failed', 'field_errors' => [['field' => 'dropoff_address', 'error' => 'Not found'], ['field' => 'pickup_address', 'error' => 'Not supported']]],
        'dropoff_address: Not found; pickup_address: Not supported',
        false,
    ],
    'a malformed request, naming no field' => [400, ['message' => 'Validation Failed'], 'Validation Failed', false],
]);

it('tells a refusal from DoorDash being unreachable or the key not working', function (int $status, string $exception) {
    Http::fake(['openapi.doordash.com/*' => Http::response(['message' => 'Nope'], $status)]);

    expect(fn () => $this->gateway->acceptQuote($this->restaurant, 'order-1'))->toThrow($exception);
})->with([
    'unknown delivery' => [404, DoorDashRefused::class],
    'duplicate ID' => [409, DoorDashRefused::class],
    'key not accepted' => [401, DoorDashKeyRejected::class],
    'key not allowed' => [403, DoorDashKeyRejected::class],
    'too many requests' => [429, CourierUnavailable::class],
    'DoorDash’s own fault' => [500, CourierUnavailable::class],
]);

it('tries looking a delivery up again when DoorDash can’t be reached', function () {
    Http::fake(['openapi.doordash.com/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(driveDelivery(['delivery_status' => 'picked_up', 'dasher_name' => 'Priya S.']))]);

    $delivery = $this->gateway->delivery($this->restaurant, 'order-1');

    expect($delivery->status)->toBe('picked_up')
        ->and($delivery->dasherName)->toBe('Priya S.');
});

it('is unavailable when DoorDash can’t be reached', function () {
    Http::fake(['openapi.doordash.com/*' => Http::failedConnection()]);

    expect(fn () => $this->gateway->quote($this->restaurant, ['external_delivery_id' => 'order-1']))->toThrow(CourierUnavailable::class);
});

it('checks an access key by asking for a delivery that can’t exist', function () {
    Http::fake(['openapi.doordash.com/*' => Http::sequence()
        ->push(['message' => 'Unknown delivery ID'], 404)
        ->push(['message' => 'Request unauthorized'], 401)]);

    $this->gateway->checkCredentials($this->restaurant);

    expect(fn () => $this->gateway->checkCredentials($this->restaurant))->toThrow(DoorDashKeyRejected::class);
});

it('asks DoorDash nothing for a restaurant without an access key', function () {
    Http::fake();
    $restaurant = Restaurant::factory()->create();

    expect(fn () => $this->gateway->quote($restaurant, ['external_delivery_id' => 'order-1']))->toThrow(CourierUnavailable::class);

    Http::assertNothingSent();
});
