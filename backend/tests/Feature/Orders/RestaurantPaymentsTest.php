<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Payments\PaymentGateway;
use App\Payments\PaymentsUnavailable;
use App\Payments\StripePaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentGateway;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;

    // Monday 5 October 2026, 6 pm in Melbourne: open until 10 pm.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
});

function checkout(MomoMenu $menu, string $key = 'payments-key-0001'): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/restaurants/{$menu->restaurant->slug}/orders", $menu->orderPayload());
}

it('charges each order in its own restaurant’s Stripe account', function () {
    $other = MomoMenu::create();

    checkout($this->menu)->assertCreated();
    checkout($other)->assertCreated();

    expect($this->payments->restaurants)->toBe([$this->menu->restaurant->slug, $other->restaurant->slug]);
});

it('starts no order until the restaurant’s Stripe keys are in', function (array $missing) {
    $this->menu->restaurant->update($missing);

    checkout($this->menu)
        ->assertStatus(503)
        ->assertJsonPath('message', 'This restaurant isn’t taking payments online yet.');

    expect(Order::query()->count())->toBe(0)
        ->and($this->payments->createCalls)->toBe(0);
})->with([
    'no keys' => [['stripe_publishable_key' => null, 'stripe_secret_key' => null, 'stripe_webhook_secret' => null]],
    'no secret key' => [['stripe_secret_key' => null]],
    // Without it the payment would go through but the order would never reach the kitchen.
    'no webhook secret' => [['stripe_webhook_secret' => null]],
]);

it('gives the apps the publishable key once every key is in, and never a secret', function () {
    $restaurant = $this->menu->restaurant;
    $restaurant->update(['stripe_publishable_key' => 'pk_test_visible', 'stripe_secret_key' => 'sk_test_hidden', 'stripe_webhook_secret' => 'whsec_hidden']);

    $response = $this->getJson("/api/v1/restaurants/{$restaurant->slug}")->assertOk();

    expect($response->json('data.payments.stripe_publishable_key'))->toBe('pk_test_visible')
        ->and($response->getContent())->not->toContain('sk_test_hidden')
        ->and($response->getContent())->not->toContain('whsec_hidden')
        ->and($restaurant->toArray())->not->toHaveKeys(['stripe_secret_key', 'stripe_webhook_secret']);

    $restaurant->update(['stripe_webhook_secret' => null]);

    $this->getJson("/api/v1/restaurants/{$restaurant->slug}")->assertJsonPath('data.payments.stripe_publishable_key', null);
});

it('keeps the secret key and webhook secret encrypted in the database', function () {
    $this->menu->restaurant->update(['stripe_secret_key' => 'sk_test_plain', 'stripe_webhook_secret' => 'whsec_plain']);

    $row = DB::table('restaurants')->where('id', $this->menu->restaurant->id)->first();

    expect($row->stripe_secret_key)->not->toContain('sk_test_plain')
        ->and($row->stripe_webhook_secret)->not->toContain('whsec_plain')
        ->and(Restaurant::query()->find($this->menu->restaurant->id)?->stripe_secret_key)->toBe('sk_test_plain');
});

it('won’t call Stripe for a restaurant without a secret key', function () {
    $restaurant = Restaurant::factory()->withoutPayments()->create();
    $order = Order::factory()->for($restaurant)->create(['stripe_payment_intent_id' => 'pi_123']);

    expect(fn () => (new StripePaymentGateway)->retrievePaymentIntent($order))
        ->toThrow(PaymentsUnavailable::class, 'This restaurant isn’t taking payments online yet.');
});

it('keeps serving the restaurant when its saved keys are unreadable or junk', function () {
    $slug = $this->menu->restaurant->slug;
    DB::table('restaurants')->where('id', $this->menu->restaurant->id)->update([
        'stripe_publishable_key' => 'null',
        'stripe_secret_key' => 'null',
        'stripe_webhook_secret' => 'null',
    ]);

    $this->getJson("/api/v1/restaurants/{$slug}")
        ->assertOk()
        ->assertJsonPath('data.payments.stripe_publishable_key', null);

    checkout($this->menu)->assertStatus(503);
    expect(Order::query()->count())->toBe(0);
});
