<?php

use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Models\Restaurant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->withoutPayments()->create();
    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
    useBackOffice($this->restaurant);
});

it('saves the website’s domain however it’s typed or pasted', function (string $typed) {
    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => $typed])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->restaurant->refresh()->custom_domain)->toBe('order.momohouse.com.au')
        ->and($this->restaurant->webUrl())->toBe('https://order.momohouse.com.au');
})->with([
    'as it is' => 'order.momohouse.com.au',
    'in capitals' => 'Order.MomoHouse.com.au',
    'a page’s address' => ' https://Order.MomoHouse.com.au/menu?table=5 ',
    'with a trailing dot' => 'order.momohouse.com.au.',
]);

it('refuses what isn’t a domain, and another restaurant’s however it’s written', function (string $typed, string $message) {
    Restaurant::factory()->create(['custom_domain' => 'order.kathmandu.com.au']);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => $typed])
        ->call('save')
        ->assertHasFormErrors(['custom_domain'])
        ->assertSee($message);

    expect($this->restaurant->refresh()->custom_domain)->toBeNull();
})->with([
    'words' => ['not a domain', 'Enter just the website’s domain, like order.example.com.au.'],
    'localhost' => ['localhost:8081', 'Enter just the website’s domain, like order.example.com.au.'],
    'another restaurant’s' => ['https://Order.Kathmandu.com.au/', 'Another restaurant already uses this domain.'],
]);

it('keeps its own domain when other settings are saved', function () {
    $this->restaurant->update(['custom_domain' => 'order.momohouse.com.au']);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['name' => 'Renamed Kitchen'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->restaurant->refresh()->custom_domain)->toBe('order.momohouse.com.au');
});

it('clears the domain when the field is emptied', function () {
    $this->restaurant->update(['custom_domain' => 'order.momohouse.com.au']);

    Livewire::test(EditRestaurantSettings::class)
        ->fillForm(['custom_domain' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->restaurant->refresh()->custom_domain)->toBeNull();
});
