<?php

use App\Enums\PaymentProcessor;
use App\Enums\SquareEnvironment;
use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquareLocation;
use Filament\Actions\Testing\TestAction;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakeSquareGateway;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->withoutPayments()->create();
    $this->owner = User::factory()->ownerOf($this->restaurant)->create();
    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);
    /** @var FakeSquareGateway $square */
    $square = app(SquareGateway::class);
    $this->square = $square;
    $this->settings = route('filament.admin.tenant.profile', ['tenant' => $this->restaurant]);
});

/** Its own Stripe account, in test mode. */
const STRIPE_KEYS = ['stripe_publishable_key' => 'pk_test_51Abc', 'stripe_secret_key' => 'sk_test_51Def', 'stripe_webhook_secret' => 'whsec_ghi'];

function squareCallback(Restaurant $restaurant, array $query, string $state = 'state-from-the-session'): TestResponse
{
    return test()->withSession(['square_connect' => ['state' => $state, 'restaurant' => $restaurant->id, 'environment' => 'sandbox']])
        ->get('/square/oauth/callback?'.http_build_query($query));
}

it('sends the owner to Square to approve the connection', function () {
    $response = $this->get(route('square.connect', ['restaurant' => $this->restaurant, 'environment' => 'sandbox']))->assertRedirect();

    $url = (string) $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://connect.squareupsandbox.com/oauth2/authorize?')
        ->and($query)->toMatchArray([
            'client_id' => 'sandbox-sq0idb-test-app',
            'scope' => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE',
            'session' => 'false',
        ])
        ->and($query['state'])->toBe(session('square_connect.state'));

    // Production isn't set up on this platform.
    $this->get(route('square.connect', ['restaurant' => $this->restaurant, 'environment' => 'production']))->assertNotFound();
});

it('connects the Square account Square sends back, and takes payments with it', function () {
    squareCallback($this->restaurant, ['code' => 'sq0cgp-code', 'state' => 'state-from-the-session'])->assertRedirect($this->settings);

    $restaurant = $this->restaurant->refresh();

    expect($this->square->codes)->toBe(['sq0cgp-code'])
        ->and($restaurant->square_environment)->toBe(SquareEnvironment::Sandbox)
        ->and($restaurant->square_merchant_id)->toBe('MSQUARE123')
        ->and($restaurant->square_merchant_name)->toBe('Momo House on Square')
        ->and($restaurant->square_location_id)->toBe('LMAIN')
        // Stripe isn't set up, so Square is used straight away.
        ->and($restaurant->payment_processor)->toBe(PaymentProcessor::Square)
        ->and($restaurant->acceptsPayments())->toBeTrue();

    $this->get($this->settings)
        ->assertOk()
        ->assertSee('Taking test payments into your Square sandbox account')
        ->assertSee('Connected to Momo House on Square: a Square sandbox account, for test payments.')
        ->assertDontSee($restaurant->square_access_token);
});

it('refuses an answer that didn’t start here, or that wasn’t approved', function (array $query, string $state) {
    squareCallback($this->restaurant, $query, $state)->assertRedirect();

    expect($this->square->codes)->toBe([])
        ->and($this->restaurant->refresh()->squareConnected())->toBeFalse();
})->with([
    'another state' => [['code' => 'sq0cgp-code', 'state' => 'someone-elses'], 'state-from-the-session'],
    'not approved' => [['error' => 'access_denied', 'state' => 'state-from-the-session'], 'state-from-the-session'],
]);

it('only connects Square for the restaurant’s owner', function () {
    $this->actingAs(User::factory()->create());

    squareCallback($this->restaurant, ['code' => 'sq0cgp-code', 'state' => 'state-from-the-session'])->assertRedirect();
    $this->get(route('square.connect', ['restaurant' => $this->restaurant, 'environment' => 'sandbox']))->assertRedirect();

    expect($this->square->codes)->toBe([])
        ->and(session('square_connect'))->toBeNull();
});

it('takes payments with Square once the owner chooses its location', function () {
    connectSquare($this->restaurant)->forceFill([
        'payment_processor' => PaymentProcessor::Stripe,
        'square_location_id' => null,
        'square_locations' => [
            (new SquareLocation('LMAIN', 'Main Street', 'AUD', true))->toArray(),
            (new SquareLocation('LMARKET', 'Market stall', 'AUD', true))->toArray(),
            (new SquareLocation('LNZ', 'Auckland', 'NZD', true))->toArray(),
        ],
    ])->save();

    Livewire::test(EditRestaurantSettings::class)
        ->assertSee('Not taking payments yet: add the publishable key, the secret key and the webhook signing secret, or connect Square.')
        ->fillForm(['square_location_id' => 'LNZ'])
        ->call('save')
        ->assertHasFormErrors(['square_location_id'])
        ->fillForm(['square_location_id' => 'LMARKET'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Customers now pay with Square');

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->square_location_id)->toBe('LMARKET')
        ->and($restaurant->payment_processor)->toBe(PaymentProcessor::Square);
});

it('switches between Stripe and Square once both are set up', function () {
    $restaurant = connectSquare($this->restaurant);
    $restaurant->forceFill([...STRIPE_KEYS, 'payment_processor' => PaymentProcessor::Stripe])->save();
    $useSquare = TestAction::make('useSquare')->schemaComponent('payments');
    $useStripe = TestAction::make('useStripe')->schemaComponent('payments');

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist($useStripe)
        ->callAction($useSquare)
        ->assertNotified('Customers now pay with Square');

    expect($restaurant->refresh()->payment_processor)->toBe(PaymentProcessor::Square);

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist($useSquare)
        ->callAction($useStripe)
        ->assertNotified('Customers now pay with Stripe');

    expect($restaurant->refresh()->payment_processor)->toBe(PaymentProcessor::Stripe);
});

it('only offers switching to a processor that’s ready', function () {
    connectSquare($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->assertActionDoesNotExist(TestAction::make('useStripe')->schemaComponent('payments'))
        ->assertActionDoesNotExist(TestAction::make('useSquare')->schemaComponent('payments'));
});

it('checks the Square account, and registers the website for Apple Pay', function () {
    connectSquare($this->restaurant)->update(['custom_domain' => 'order.momohouse.com.au']);
    $this->restaurant->forceFill(['square_merchant_name' => null])->save();

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('checkSquare')->schemaComponent('square'))
        ->assertNotified('Your Square account is connected');

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
    connectSquare($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => 'order.momohouse.com.au'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->square->domains)->toBe(['order.momohouse.com.au'])
        ->and($this->restaurant->refresh()->squareWalletSetup()?->domain)->toBe('order.momohouse.com.au');
});

it('disconnects Square, and goes back to Stripe if it’s set up', function () {
    $restaurant = connectSquare($this->restaurant);
    $restaurant->forceFill(STRIPE_KEYS)->save();

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('disconnectSquare')->schemaComponent('square'))
        ->assertNotified('Square is disconnected');

    $restaurant->refresh();

    expect($this->square->revoked)->toBe(['MSQUARE123'])
        ->and($restaurant->squareConnected())->toBeFalse()
        ->and($restaurant->square_refresh_token)->toBeNull()
        ->and($restaurant->payment_processor)->toBe(PaymentProcessor::Stripe);
});

it('offers only the Square environments the platform has set up', function () {
    Livewire::test(EditRestaurantSettings::class)
        ->assertActionVisible(TestAction::make('connectSquareSandbox')->schemaComponent('square'))
        ->assertActionDoesNotExist(TestAction::make('connectSquare')->schemaComponent('square'))
        ->assertSee('Not connected. Press “Connect Square” to sign in to Square and approve');
});
