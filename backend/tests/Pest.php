<?php

use App\Enums\DoorDashFee;
use App\Enums\PaymentProcessor;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\OrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeDoorDashGateway;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSquareGateway;
use Tests\Support\MomoMenu;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against Sail's MySQL "testing" database, each inside a
| transaction that is rolled back afterwards.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Nothing leaves the test run: Stripe, Square and DoorDash are faked, Expo answers "ok",
        // and any other HTTP request fails the test.
        FakePaymentGateway::install();
        FakeSquareGateway::install();
        FakeDoorDashGateway::install();
        Http::preventStrayRequests();
        Http::fake(['exp.host/*' => Http::response(['data' => []])]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Give the restaurant its own (fake) Square sandbox application's credentials and webhook
 * signature key, checked, at its one AUD location, and take payments with Square.
 */
function setUpSquare(Restaurant $restaurant): Restaurant
{
    $restaurant->forceFill([
        'payment_processor' => PaymentProcessor::Square,
        'square_application_id' => 'sandbox-sq0idb-test-app',
        'square_access_token' => 'EAAA-token',
        'square_webhook_signature_key' => 'test-square-signature-key',
        'square_merchant_name' => 'Momo House on Square',
        'square_location_id' => 'LMAIN',
        'square_locations' => [['id' => 'LMAIN', 'name' => 'Main Street', 'currency' => 'AUD', 'active' => true]],
    ])->save();

    return $restaurant;
}

/**
 * Have DoorDash's drivers deliver the restaurant's delivery orders, with its own (fake)
 * DoorDash sandbox access key, and customers paying the zone's fee or DoorDash's price.
 */
function setUpDoorDash(Restaurant $restaurant, DoorDashFee $fee = DoorDashFee::Zone): Restaurant
{
    $restaurant->forceFill([
        'doordash_enabled' => true,
        'doordash_fee' => $fee,
        'doordash_developer_id' => 'dev-1234',
        'doordash_key_id' => 'key-5678',
        'doordash_signing_secret' => 'c2lnbmluZy1zZWNyZXQtZm9yLXRlc3Rz',
        'doordash_webhook_secret' => 'ddwh_test_secret',
        'phone' => '03 5550 1234',
    ])->save();

    return $restaurant;
}

/**
 * A paid delivery order waiting for the kitchen: placed through the API as the app places it
 * (so it has its items, and DoorDash as its courier when the restaurant delivers with
 * DoorDash), then paid.
 *
 * @param  array<string, mixed>  $overrides  top-level keys of the order request
 */
function placeDeliveryOrder(MomoMenu $menu, array $overrides = []): Order
{
    test()->withHeader('Idempotency-Key', 'delivery-'.Str::lower(Str::random(12)))
        ->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders", $menu->deliveryPayload($overrides))
        ->assertCreated();

    $order = Order::query()->latest('id')->firstOrFail();

    return app(OrderService::class)->markPaid($order, (string) $order->stripe_payment_intent_id, $order->total_cents);
}

/**
 * Serve the owner back office for a restaurant, as an HTTP request to it would.
 */
function useBackOffice(Restaurant $restaurant): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($restaurant);
    Filament::bootCurrentPanel();
}
