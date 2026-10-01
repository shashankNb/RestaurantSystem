<?php

use App\Enums\RestaurantRole;
use App\Filament\Resources\Memberships\Pages\ManageMemberships;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create();
    $this->owner = User::factory()->ownerOf($this->restaurant)->create();

    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);
});

it('creates a staff account for a new email', function () {
    Livewire::test(ManageMemberships::class)
        ->callAction('create', data: [
            'name' => 'Dawa Cook',
            'email' => 'Dawa@Example.com',
            'password' => 'kitchen-tablet-2026',
            'role' => RestaurantRole::Staff->value,
        ])
        ->assertHasNoActionErrors();

    $user = User::query()->where('email', 'dawa@example.com')->sole();

    expect($user->roleAt($this->restaurant))->toBe(RestaurantRole::Staff)
        ->and(Hash::check('kitchen-tablet-2026', $user->password))->toBeTrue();
});

it('links an existing account without changing its password', function () {
    $customer = User::factory()->create(['email' => 'regular@example.com', 'password' => 'their-own-password']);

    Livewire::test(ManageMemberships::class)
        ->callAction('create', data: [
            'name' => 'Ignored name',
            'email' => 'regular@example.com',
            'password' => 'owner-chosen-password',
            'role' => RestaurantRole::Staff->value,
        ])
        ->assertHasNoActionErrors();

    $customer->refresh();

    expect($customer->worksAt($this->restaurant))->toBeTrue()
        ->and(Hash::check('their-own-password', $customer->password))->toBeTrue()
        ->and($customer->name)->not->toBe('Ignored name');
});

it('refuses to add someone who already has access', function () {
    $staff = User::factory()->staffOf($this->restaurant)->create();

    Livewire::test(ManageMemberships::class)
        ->callAction('create', data: [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => 'another-password-1',
            'role' => RestaurantRole::Staff->value,
        ])
        ->assertHasActionErrors(['email']);
});

it('does not let owners remove their own access or the last owner', function () {
    $ownMembership = $this->restaurant->memberships()->where('user_id', $this->owner->id)->sole();

    Livewire::test(ManageMemberships::class)
        ->assertActionDisabled(TestAction::make('delete')->table($ownMembership));
});

it('lets owners remove a staff member', function () {
    $staff = User::factory()->staffOf($this->restaurant)->create();
    $membership = $this->restaurant->memberships()->where('user_id', $staff->id)->sole();

    Livewire::test(ManageMemberships::class)
        ->callAction(TestAction::make('delete')->table($membership))
        ->assertHasNoActionErrors();

    expect($staff->fresh()->worksAt($this->restaurant))->toBeFalse()
        ->and(User::query()->whereKey($staff->id)->exists())->toBeTrue();
});
