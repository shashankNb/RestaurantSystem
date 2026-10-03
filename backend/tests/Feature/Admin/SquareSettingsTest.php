<?php

use App\Enums\PaymentProcessor;
use App\Enums\SquareEnvironment;
use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquareLocation;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\FakeSquareGateway;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->withoutPayments()->create();
    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
    useBackOffice($this->restaurant);
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $this->square = $square;
    $this->settings = "/admin/{$this->restaurant->slug}/profile";
});

/** Its own Stripe account, in test mode. */
const STRIPE_TEST_KEYS = ['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi'];

it('saves the restaurant’s own Square credentials, checks them, and takes payments with Square', function () {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm([
            'square_application_id' => ' sandbox-sq0idb-ZtnR8xVALELQs4jyaXoyfg ',
            'square_access_token' => 'EAAAl-sandbox-token',
            'square_webhook_signature_key' => 'signature-key-1',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Customers now pay with Square')
        ->assertSchemaStateSet(['square_location_id' => 'LMAIN']);

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->square_application_id)->toBe('sandbox-sq0idb-ZtnR8xVALELQs4jyaXoyfg')
        ->and($restaurant->square_access_token)->toBe('EAAAl-sandbox-token')
        ->and($restaurant->square_webhook_signature_key)->toBe('signature-key-1')
        ->and($restaurant->squareEnvironment())->toBe(SquareEnvironment::Sandbox)
        ->and($restaurant->square_merchant_name)->toBe('Momo House on Square')
        // Its only Australian-dollar location is chosen for it.
        ->and($restaurant->square_location_id)->toBe('LMAIN')
        // Stripe isn't set up, so customers pay with Square straight away.
        ->and($restaurant->payment_processor)->toBe(PaymentProcessor::Square)
        ->and($restaurant->acceptsPayments())->toBeTrue();

    $this->get($this->settings)
        ->assertOk()
        ->assertSee('Taking test payments into your Square sandbox account')
        ->assertSee('Momo House on Square, in Square’s sandbox: test payments only.')
        ->assertSee(route('square.webhook', ['restaurant' => $restaurant]))
        ->assertDontSee('EAAAl-sandbox-token')
        ->assertDontSee('signature-key-1');
});

it('refuses Square credentials pasted in the wrong place', function (array $credentials, array $errors) {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm($credentials)
        ->call('save')
        ->assertHasFormErrors($errors);

    expect($this->restaurant->refresh()->squareConfigured())->toBeFalse();
})->with([
    'swapped' => [['square_application_id' => 'EAAAl-sandbox-token', 'square_access_token' => 'sandbox-sq0idb-ZtnR8x'], ['square_application_id', 'square_access_token']],
    'the application secret' => [['square_application_id' => 'sandbox-sq0csb-secret', 'square_access_token' => 'EAAAl-token'], ['square_application_id']],
    'not an access token' => [['square_application_id' => 'sq0idp-live', 'square_access_token' => 'my-password'], ['square_access_token']],
]);

it('keeps the saved Square secrets when their fields are left empty', function () {
    setUpSquare($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->assertSchemaStateSet(['square_application_id' => 'sandbox-sq0idb-test-app', 'square_access_token' => null, 'square_webhook_signature_key' => null])
        ->fillForm(['name' => 'Renamed Kitchen'])
        ->call('save')
        ->assertHasNoFormErrors();

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->square_access_token)->toBe('EAAA-token')
        ->and($restaurant->square_webhook_signature_key)->toBe('test-square-signature-key')
        ->and($restaurant->square_location_id)->toBe('LMAIN');
});

it('says when Square doesn’t accept the access token', function () {
    $this->square->rejectToken = true;

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['square_application_id' => 'sq0idp-live-app', 'square_access_token' => 'EAAA-from-the-sandbox'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Square didn’t accept the access token');

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->squareConfigured())->toBeTrue()
        ->and($restaurant->acceptsPaymentsWith(PaymentProcessor::Square))->toBeFalse();

    $this->get($this->settings)->assertSee('Not checked yet: press “Check with Square”.');
});

it('takes payments with Square once the owner chooses its location', function () {
    $this->square->locations = [
        new SquareLocation('LMAIN', 'Main Street', 'AUD', true),
        new SquareLocation('LMARKET', 'Market stall', 'AUD', true),
        new SquareLocation('LNZ', 'Auckland', 'NZD', true),
    ];

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['square_application_id' => 'sandbox-sq0idb-test-app', 'square_access_token' => 'EAAA-token'])
        ->call('save')
        ->assertNotified('Your Square credentials work');

    expect($this->restaurant->refresh()->square_location_id)->toBeNull()
        ->and($this->restaurant->payment_processor)->toBe(PaymentProcessor::Stripe);

    Livewire::test(EditRestaurantSettings::class)
        ->assertSee('Not taking payments yet: add the publishable key, the secret key and the webhook signing secret, or set up Square.')
        ->fillForm(['square_location_id' => 'LNZ'])
        ->call('save')
        ->assertHasFormErrors(['square_location_id'])
        ->fillForm(['square_location_id' => 'LMARKET'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Customers now pay with Square');

    expect($this->restaurant->refresh()->square_location_id)->toBe('LMARKET')
        ->and($this->restaurant->payment_processor)->toBe(PaymentProcessor::Square);
});

it('switches between Stripe and Square once both are set up', function () {
    setUpSquare($this->restaurant)->forceFill([...STRIPE_TEST_KEYS, 'payment_processor' => PaymentProcessor::Stripe])->save();
    $useSquare = TestAction::make('useSquare')->schemaComponent('payments');
    $useStripe = TestAction::make('useStripe')->schemaComponent('payments');

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist($useStripe)
        ->callAction($useSquare)
        ->assertNotified('Customers now pay with Square');

    expect($this->restaurant->refresh()->payment_processor)->toBe(PaymentProcessor::Square);

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist($useSquare)
        ->callAction($useStripe)
        ->assertNotified('Customers now pay with Stripe');

    expect($this->restaurant->refresh()->payment_processor)->toBe(PaymentProcessor::Stripe);
});

it('only offers switching to a processor that’s ready', function () {
    setUpSquare($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist(TestAction::make('useStripe')->schemaComponent('payments'))
        ->assertActionDoesNotExist(TestAction::make('useSquare')->schemaComponent('payments'));
});

it('checks the Square account, and registers the website for Apple Pay', function () {
    setUpSquare($this->restaurant)->update(['custom_domain' => 'order.momohouse.com.au']);
    $this->restaurant->forceFill(['square_merchant_name' => null])->save();

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('checkSquare')->schemaComponent('square'))
        ->assertNotified('Your Square credentials work');

    $restaurant = $this->restaurant->refresh();

    expect($this->square->domains)->toBe(['order.momohouse.com.au'])
        ->and($restaurant->square_merchant_name)->toBe('Momo House on Square')
        ->and($restaurant->squareWalletSetup()?->domainReady)->toBeTrue();

    $this->get($this->settings)
        ->assertOk()
        ->assertSee('Google Pay: ready.')
        ->assertSee('Apple Pay: order.momohouse.com.au is registered with Square.');
});

it('registers a new website domain with Square too', function () {
    setUpSquare($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => 'order.momohouse.com.au'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->square->domains)->toBe(['order.momohouse.com.au'])
        ->and($this->restaurant->refresh()->squareWalletSetup()?->domain)->toBe('order.momohouse.com.au');
});

it('says how to set Square up before it is', function () {
    $this->get($this->settings)
        ->assertOk()
        ->assertSee('Not set up. Enter your Square application’s ID and access token below.')
        ->assertSee(route('square.webhook', ['restaurant' => $this->restaurant]));
});
