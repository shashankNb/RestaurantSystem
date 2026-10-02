<?php

use App\Models\Restaurant;

beforeEach(function () {
    config(['cors.configured_origins' => ['http://localhost:8081']]);
    $this->restaurant = Restaurant::factory()->create(['custom_domain' => 'order.kathmandu.test']);
    $this->url = "/api/v1/restaurants/{$this->restaurant->slug}";
});

it('lets each restaurant’s own website call the API, and the configured origins', function (string $origin, bool $allowed) {
    $response = $this->getJson($this->url, ['Origin' => $origin])->assertOk();

    $allowed
        ? $response->assertHeader('Access-Control-Allow-Origin', $origin)
        : $response->assertHeaderMissing('Access-Control-Allow-Origin');
})->with([
    'the restaurant’s domain' => ['https://order.kathmandu.test', true],
    'CORS_ALLOWED_ORIGINS' => ['http://localhost:8081', true],
    'over plain http' => ['http://order.kathmandu.test', false],
    'anyone else' => ['https://elsewhere.test', false],
]);

it('follows a restaurant moving to another domain', function () {
    $this->getJson($this->url, ['Origin' => 'https://order.kathmandu.test'])->assertHeader('Access-Control-Allow-Origin', 'https://order.kathmandu.test');

    $this->restaurant->update(['custom_domain' => 'kathmandu.test']);

    $this->getJson($this->url, ['Origin' => 'https://kathmandu.test'])->assertHeader('Access-Control-Allow-Origin', 'https://kathmandu.test');
    $this->getJson($this->url, ['Origin' => 'https://order.kathmandu.test'])->assertHeaderMissing('Access-Control-Allow-Origin');
});
