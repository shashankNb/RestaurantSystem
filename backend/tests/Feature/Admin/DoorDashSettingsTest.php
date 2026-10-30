<?php

use App\Delivery\DoorDash\DoorDashGateway;
use App\Enums\Courier;
use App\Enums\CourierStatus;
use App\Enums\DoorDashFee;
use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeDoorDashGateway;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create(['phone' => '03 5550 1234']);
    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
    useBackOffice($this->restaurant);
    /** @var FakeDoorDashGateway $doordash */
    $doordash = app(DoorDashGateway::class);
    $this->doordash = $doordash;
    $this->settings = "/admin/{$this->restaurant->slug}/profile";
});

/** An access key from the restaurant's own DoorDash developer account. */
const DOORDASH_KEY = [
    'doordash_developer_id' => '0b5c3a5e-1d2f-4c8b-9e7a-6f1d2c3b4a59',
    'doordash_key_id' => '7d9e8f6a-5b4c-4d3e-8f2a-1b0c9d8e7f6a',
    'doordash_signing_secret' => 'c2lnbmluZy1zZWNyZXQtZm9yLXRlc3Rz',
];

it('saves the restaurant’s own DoorDash access key, checks it, and switches DoorDash on', function () {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm([...DOORDASH_KEY, 'doordash_enabled' => true, 'doordash_fee' => DoorDashFee::Quote->value])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('DoorDash accepted your access key');

    $restaurant = $this->restaurant->refresh();

    expect($restaurant->doordash_developer_id)->toBe(DOORDASH_KEY['doordash_developer_id'])
        ->and($restaurant->doordash_signing_secret)->toBe(DOORDASH_KEY['doordash_signing_secret'])
        // Encrypted in the database.
        ->and(DB::table('restaurants')->where('id', $restaurant->id)->value('doordash_signing_secret'))->not->toContain(DOORDASH_KEY['doordash_signing_secret'])
        ->and($restaurant->doordash_fee)->toBe(DoorDashFee::Quote)
        ->and($restaurant->deliversWithDoorDash())->toBeTrue()
        // The value for DoorDash's webhook is made for it.
        ->and($restaurant->doordash_webhook_secret)->toStartWith('ddwh_');

    $this->get($this->settings)
        ->assertOk()
        ->assertSee(route('doordash.webhook', ['restaurant' => $restaurant]))
        ->assertSee((string) $restaurant->doordash_webhook_secret)
        ->assertDontSee(DOORDASH_KEY['doordash_signing_secret']);
});

it('won’t switch DoorDash on without its access key, or a phone number for its drivers', function (array $form, string $error) {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['doordash_enabled' => true, ...$form])
        ->call('save')
        ->assertHasFormErrors(['doordash_enabled' => $error]);

    expect($this->restaurant->refresh()->doordash_enabled)->toBeFalse();
})->with([
    'no access key' => [[], 'Enter your DoorDash access key’s developer ID, key ID and signing secret first.'],
    'no phone number' => [[...DOORDASH_KEY, 'phone' => ''], 'Add the restaurant’s phone number under Details first, for DoorDash’s drivers to call.'],
]);

it('keeps the saved signing secret and webhook value when the field is left empty', function () {
    setUpDoorDash($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->assertSchemaStateSet(['doordash_signing_secret' => null])
        ->fillForm(['name' => 'Renamed Kitchen'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->restaurant->refresh()->doordash_signing_secret)->toBe('c2lnbmluZy1zZWNyZXQtZm9yLXRlc3Rz')
        ->and($this->restaurant->doordash_webhook_secret)->toBe('ddwh_test_secret')
        ->and($this->doordash->quotes)->toBe([]);
});

it('says when DoorDash doesn’t accept the access key', function () {
    $this->doordash->rejectKey = true;

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(DOORDASH_KEY)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('DoorDash didn’t accept the access key');
});

it('checks the access key again on request', function () {
    setUpDoorDash($this->restaurant);

    Livewire::test(EditRestaurantSettings::class)
        ->callAction(TestAction::make('checkDoorDash')->schemaComponent('doordash'))
        ->assertNotified('DoorDash accepted your access key');
});

it('shows an order’s DoorDash delivery', function () {
    $order = Order::factory()->for($this->restaurant)->delivery()->accepted()->create();
    $order->forceFill([
        'courier' => Courier::DoorDash,
        'courier_delivery_id' => $order->public_id,
        'courier_status' => CourierStatus::Cancelled,
        'courier_support_reference' => '1234567',
        'courier_problem' => 'DoorDash cancelled the delivery (dasher not available). Book another driver, or deliver it yourselves.',
        'courier_fee_cents' => 975,
    ])->save();

    $this->get("/admin/{$this->restaurant->slug}/orders/{$order->id}")
        ->assertOk()
        ->assertSee('Delivery cancelled')
        ->assertSee('1234567')
        ->assertSee('DoorDash cancelled the delivery (dasher not available).')
        ->assertSee('$9.75');
});
