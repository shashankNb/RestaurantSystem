<?php

use App\Enums\PaymentProcessor;
use App\Models\Restaurant;
use App\Payments\Square\SquareGateway;
use Tests\Support\FakeSquareGateway;

it('renews Square tokens more than a week old, and forgets connections Square refuses', function () {
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $fresh = connectSquare(Restaurant::factory()->create());
    $old = connectSquare(Restaurant::factory()->create(), 'MOLD');
    $old->forceFill(['square_token_expires_at' => now()->addDays(20)])->save();
    $revoked = connectSquare(Restaurant::factory()->create(), 'MREVOKED');
    $revoked->forceFill(['square_token_expires_at' => now()->addDays(10)])->save();
    $square->refuseRefreshFor = ['MREVOKED'];

    $this->artisan('square:refresh-tokens')
        ->expectsOutputToContain('Renewed 1 Square connection; 1 no longer accepted.')
        ->assertSuccessful();

    expect($old->refresh()->square_access_token)->toBe('EAAA-renewed-1')
        ->and($old->square_token_expires_at?->isAfter(now()->addDays(29)))->toBeTrue()
        ->and($fresh->refresh()->square_access_token)->toBe('EAAA-token')
        ->and($revoked->refresh()->squareConnected())->toBeFalse()
        // Its Stripe account is set up, so customers pay with Stripe again.
        ->and($revoked->payment_processor)->toBe(PaymentProcessor::Stripe);
});

it('keeps the tokens when Square can’t be reached, to try again tomorrow', function () {
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $restaurant = connectSquare(Restaurant::factory()->create());
    $restaurant->forceFill(['square_token_expires_at' => now()->addDays(20)])->save();
    $square->failNext = true;

    $this->artisan('square:refresh-tokens')->assertSuccessful();

    expect($restaurant->refresh()->square_access_token)->toBe('EAAA-token')
        ->and($restaurant->squareConnected())->toBeTrue();
});
