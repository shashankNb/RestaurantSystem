<?php

use App\Enums\PaymentProcessor;
use App\Enums\SquareEnvironment;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSquareGateway;
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
        // Nothing leaves the test run: Stripe and Square are faked, Expo answers "ok", and
        // any other HTTP request fails the test.
        FakePaymentGateway::install();
        FakeSquareGateway::install();
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
 * Connect the restaurant's (fake) Square sandbox account, at its one AUD location, and take
 * payments with Square.
 */
function connectSquare(Restaurant $restaurant, string $merchantId = 'MSQUARE123'): Restaurant
{
    $restaurant->forceFill([
        'payment_processor' => PaymentProcessor::Square,
        'square_environment' => SquareEnvironment::Sandbox,
        'square_merchant_id' => $merchantId,
        'square_merchant_name' => 'Momo House on Square',
        'square_access_token' => 'EAAA-token',
        'square_refresh_token' => 'EQAA-token',
        'square_token_expires_at' => now()->addDays(30),
        'square_location_id' => 'LMAIN',
        'square_locations' => [['id' => 'LMAIN', 'name' => 'Main Street', 'currency' => 'AUD', 'active' => true]],
    ])->save();

    return $restaurant;
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
