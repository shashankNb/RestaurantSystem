<?php

use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\PaymentGateway;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->withoutPayments()->create();
    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
    useBackOffice($this->restaurant);
});

it('saves the restaurant’s own Stripe keys', function () {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm([
            'stripe_publishable_key' => 'pk_test_51Abc',
            'stripe_secret_key' => ' sk_test_51Def ',
            'stripe_webhook_secret' => 'whsec_ghi',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->stripe_publishable_key)->toBe('pk_test_51Abc')
        ->and($restaurant->stripe_secret_key)->toBe('sk_test_51Def')
        ->and($restaurant->stripe_webhook_secret)->toBe('whsec_ghi')
        ->and($restaurant->acceptsPayments())->toBeTrue();
});

it('takes test keys as readily as live ones', function (string $mode, string $status) {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm([
            'stripe_publishable_key' => "pk_{$mode}_51Abc",
            'stripe_secret_key' => "sk_{$mode}_51Def",
            'stripe_webhook_secret' => 'whsec_ghi',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->restaurant->refresh()->acceptsPayments())->toBeTrue();

    $this->get("/admin/{$this->restaurant->slug}/profile")->assertOk()->assertSee($status);
})->with([
    'test' => ['test', 'Taking test payments into your Stripe account, in test mode'],
    'live' => ['live', 'Taking payments into your Stripe account, in live mode.'],
]);

it('refuses keys pasted the wrong way round, or from different modes', function (array $keys, array $errors) {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['stripe_webhook_secret' => 'whsec_ghi', ...$keys])
        ->call('save')
        ->assertHasFormErrors($errors);

    expect($this->restaurant->refresh()->acceptsPayments())->toBeFalse();
})->with([
    'swapped' => [['stripe_publishable_key' => 'sk_test_51Def', 'stripe_secret_key' => 'pk_test_51Abc'], ['stripe_publishable_key', 'stripe_secret_key']],
    'test and live' => [['stripe_publishable_key' => 'pk_live_51Abc', 'stripe_secret_key' => 'sk_test_51Def'], ['stripe_publishable_key']],
    'not a key' => [['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'my-password'], ['stripe_secret_key']],
    'not a signing secret' => [['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'sk_test_oops'], ['stripe_webhook_secret']],
]);

it('keeps the saved secrets when their fields are left empty, and never shows them', function () {
    $this->restaurant->update(['stripe_publishable_key' => 'pk_live_51Abc', 'stripe_secret_key' => 'sk_live_51Def', 'stripe_webhook_secret' => 'whsec_ghi']);

    Livewire::test(EditRestaurantSettings::class)
        ->assertSchemaStateSet(['stripe_publishable_key' => 'pk_live_51Abc', 'stripe_secret_key' => null, 'stripe_webhook_secret' => null])
        ->fillForm(['name' => 'Renamed Kitchen'])
        ->call('save')
        ->assertHasNoFormErrors();

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->name)->toBe('Renamed Kitchen')
        ->and($restaurant->stripe_secret_key)->toBe('sk_live_51Def')
        ->and($restaurant->stripe_webhook_secret)->toBe('whsec_ghi');

    $this->get("/admin/{$restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Taking payments into your Stripe account, in live mode.')
        ->assertSee(route('stripe.webhook', ['restaurant' => $restaurant]))
        ->assertDontSee('sk_live_51Def')
        ->assertDontSee('whsec_ghi');
});

it('checks the keys with Stripe, and how ready Apple Pay and Google Pay are', function () {
    $this->restaurant->update(['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi', 'custom_domain' => 'order.momohouse.com.au']);
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('checkStripeKeys')->schemaComponent('stripe'))
        ->assertNotified('Your Stripe keys work');

    expect($payments->domains)->toBe(['order.momohouse.com.au'])
        ->and($this->restaurant->refresh()->stripe_wallets)->toMatchArray(['domain' => 'order.momohouse.com.au', 'domain_ready' => true]);

    $payments->failNext = true;

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('checkStripeKeys')->schemaComponent('stripe'))
        ->assertNotified('Stripe didn’t accept the secret key');
});

it('gets Apple Pay and Google Pay ready on the restaurant’s website once the keys are in', function () {
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm([
            'stripe_publishable_key' => 'pk_test_51Abc',
            'stripe_secret_key' => 'sk_test_51Def',
            'stripe_webhook_secret' => 'whsec_ghi',
            'custom_domain' => 'order.momohouse.com.au',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($payments->domains)->toBe(['order.momohouse.com.au'])
        ->and($this->restaurant->refresh()->stripe_wallets)->toMatchArray([
            'apple_pay' => true,
            'google_pay' => false,
            'domain' => 'order.momohouse.com.au',
            'domain_ready' => true,
        ]);

    $this->get("/admin/{$this->restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Apple Pay: on in your Stripe account.')
        ->assertSee('Google Pay: off in your Stripe account, so customers don’t see it.')
        ->assertSee('order.momohouse.com.au: registered with Stripe, ready for both.')
        ->assertSee('Turn on Apple Pay and Google Pay');
});

it('explains that Apple Pay and Google Pay need a public website', function () {
    // The tests' ordering site, order.example.test, isn't a domain Stripe can register.
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($payments->domains)->toBe([])
        ->and($this->restaurant->refresh()->stripe_wallets)->toMatchArray(['domain' => null, 'domain_ready' => false]);

    $this->get("/admin/{$this->restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Your website: Stripe only shows them on a public website, not on localhost.');
});

it('only asks Stripe again when the keys or the domain change, and keeps the last answer if it doesn’t answer', function () {
    $earlier = ['apple_pay' => true, 'google_pay' => true, 'domain' => 'order.momohouse.com.au', 'domain_ready' => true, 'domain_problem' => null, 'checked_at' => '2026-10-01T09:00:00Z'];
    $this->restaurant->forceFill(['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi', 'custom_domain' => 'order.momohouse.com.au', 'stripe_wallets' => $earlier])->save();
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['name' => 'Renamed Kitchen'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($payments->domains)->toBe([])
        ->and($this->restaurant->refresh()->stripe_wallets)->toEqual($earlier);

    $payments->failNext = true;

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => 'order.momo.com.au'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Apple Pay and Google Pay couldn’t be set up');

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->custom_domain)->toBe('order.momo.com.au')
        ->and($restaurant->stripe_wallets)->toEqual($earlier);

    $this->get("/admin/{$restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Checked 1 Oct, 7:00 pm.');
});

it('turns on Apple Pay and Google Pay in the restaurant’s Stripe account, when the owner asks', function () {
    $this->restaurant->update(['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi']);
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $turnOn = TestAction::make('turnOnWallets')->schemaComponent('stripe');

    // Filament leaves a hidden header action out of the section altogether.
    $page = Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist($turnOn)
        ->callAction(TestAction::make('checkStripeKeys')->schemaComponent('stripe'))
        ->assertActionVisible($turnOn);

    expect($payments->turnOnCalls)->toBe(0);

    $payments->failNext = true;

    $page->callAction($turnOn)->assertNotified('Stripe didn’t switch them on');

    expect($payments->turnOnCalls)->toBe(0)
        ->and($this->restaurant->refresh()->stripe_wallets)->toMatchArray(['google_pay' => false]);

    $page->callAction($turnOn)
        ->assertNotified('Apple Pay and Google Pay are on in your Stripe account')
        ->assertActionDoesNotExist($turnOn);

    expect($payments->turnOnCalls)->toBe(1)
        ->and($this->restaurant->refresh()->stripe_wallets)->toMatchArray(['apple_pay' => true, 'google_pay' => true]);
});

it('still opens when a saved secret can’t be decrypted, and asks for it again', function () {
    // Written straight into the database, or encrypted with an APP_KEY that's been replaced.
    DB::table('restaurants')->where('id', $this->restaurant->id)->update([
        'stripe_publishable_key' => 'pk_test_51Abc',
        'stripe_secret_key' => 'null',
        'stripe_webhook_secret' => 'not-encrypted',
    ]);

    $this->get("/admin/{$this->restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Not taking payments yet: add the secret key and the webhook signing secret, or connect Square.');

    DB::table('restaurants')->where('id', $this->restaurant->id)->update(['stripe_publishable_key' => 'NULL']);

    $this->get("/admin/{$this->restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Not taking payments yet: add the publishable key, the secret key and the webhook signing secret, or connect Square.');
});
