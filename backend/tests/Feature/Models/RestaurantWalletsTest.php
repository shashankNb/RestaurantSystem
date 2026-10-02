<?php

use App\Models\Restaurant;

it('registers the website’s domain for Apple Pay and Google Pay, when Stripe can', function (?string $customDomain, string $orderingSite, ?string $domain) {
    config(['ordering.web_url' => $orderingSite]);

    expect(Restaurant::factory()->make(['custom_domain' => $customDomain])->walletDomain())->toBe($domain);
})->with([
    'its own domain' => ['order.momohouse.com.au', 'http://localhost:8081', 'order.momohouse.com.au'],
    'the ordering site' => [null, 'https://Order.Example.com/', 'order.example.com'],
    'localhost' => [null, 'http://localhost:8081', null],
    'an IP address' => [null, 'http://192.168.1.20:8081', null],
    'a local name' => [null, 'https://momo.test', null],
]);

it('forgets the last Apple Pay and Google Pay check when the secret key changes', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->forceFill(['stripe_wallets' => ['apple_pay' => true, 'google_pay' => true, 'domain' => 'order.momohouse.com.au', 'domain_ready' => true]])->save();

    $restaurant->update(['stripe_publishable_key' => 'pk_test_51New', 'custom_domain' => 'order.momo.com.au']);

    expect($restaurant->refresh()->stripe_wallets)->not->toBeNull();

    // Another Stripe account, perhaps: the check was of the old one.
    $restaurant->update(['stripe_secret_key' => 'sk_test_51New']);

    expect($restaurant->refresh()->stripe_wallets)->toBeNull();
});
