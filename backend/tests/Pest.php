<?php

use App\Models\Restaurant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePaymentGateway;
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
        // Nothing leaves the test run: Stripe is faked, Expo answers "ok", and any other
        // HTTP request fails the test.
        FakePaymentGateway::install();
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
 * Serve the owner back office for a restaurant, as an HTTP request to it would.
 */
function useBackOffice(Restaurant $restaurant): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($restaurant);
    Filament::bootCurrentPanel();
}
