<?php

use App\Enums\RestaurantRole;
use App\Mail\OwnerInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;

beforeEach(fn () => Mail::fake());

function createRestaurant(array $options): PendingCommand
{
    return test()->artisan('restaurant:create', [
        '--name' => 'Kathmandu Kitchen',
        '--owner-email' => 'asha@example.com',
        '--owner-name' => 'Asha Gurung',
        '--no-interaction' => true,
        ...$options,
    ]);
}

it('adds a restaurant ready to set up, and invites its new owner to choose a password', function () {
    createRestaurant(['--phone' => '03 5550 0199', '--brand-color' => '#1f5a7a'])
        ->expectsOutputToContain('Added Kathmandu Kitchen (kathmandu-kitchen), owned by Asha Gurung <asha@example.com>.')
        ->assertSuccessful();

    $restaurant = Restaurant::query()->where('slug', 'kathmandu-kitchen')->sole();
    $owner = User::query()->where('email', 'asha@example.com')->sole();

    expect($restaurant->name)->toBe('Kathmandu Kitchen')
        ->and($restaurant->timezone)->toBe('Australia/Melbourne')
        ->and($restaurant->currency)->toBe('AUD')
        ->and($restaurant->phone)->toBe('03 5550 0199')
        ->and($restaurant->brand_color)->toBe('#1F5A7A')
        ->and($restaurant->pickup_enabled)->toBeTrue()
        ->and($restaurant->delivery_enabled)->toBeFalse()
        ->and($restaurant->dine_in_enabled)->toBeFalse()
        ->and($restaurant->acceptsPayments())->toBeFalse()
        ->and($owner->name)->toBe('Asha Gurung')
        ->and($owner->isOwnerOf($restaurant))->toBeTrue();

    Mail::assertSent(OwnerInvitation::class, function (OwnerInvitation $mail) use ($restaurant): bool {
        return $mail->hasTo('asha@example.com')
            && $mail->restaurant->is($restaurant)
            && $mail->newAccount
            && str_contains($mail->url, '/admin/password-reset/reset');
    });

    // The link opens the page to choose a password.
    $invitation = Mail::sent(OwnerInvitation::class)->first();
    $this->get($invitation->url)->assertOk();
});

it('makes someone with an account the owner, with a link to the back office', function () {
    $existing = User::factory()->create(['email' => 'asha@example.com', 'name' => 'Asha G']);

    createRestaurant(['--owner-name' => null])->assertSuccessful();

    $restaurant = Restaurant::query()->where('slug', 'kathmandu-kitchen')->sole();

    expect(User::query()->where('email', 'asha@example.com')->count())->toBe(1)
        ->and($existing->fresh()?->isOwnerOf($restaurant))->toBeTrue()
        ->and($restaurant->memberships()->sole()->role)->toBe(RestaurantRole::Owner);

    Mail::assertSent(OwnerInvitation::class, fn (OwnerInvitation $mail): bool => ! $mail->newAccount
        && str_ends_with($mail->url, "/admin/{$restaurant->slug}"));
});

it('prints the owner’s link instead of emailing it', function () {
    createRestaurant(['--no-invite' => true])
        ->expectsOutputToContain('Owner’s link to choose a password')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

it('refuses a link name another restaurant has, or details that won’t work', function (array $options, string $error) {
    Restaurant::factory()->create(['slug' => 'kathmandu-kitchen']);

    createRestaurant($options)->expectsOutputToContain($error)->assertFailed();

    expect(Restaurant::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'asha@example.com')->exists())->toBeFalse();
    Mail::assertNothingSent();
})->with([
    'link name taken' => [[], 'Another restaurant already has that link name.'],
    'link name with spaces' => [['--slug' => 'Kathmandu Kitchen 2'], 'The link name can only have lower-case letters, numbers and dashes'],
    'not an email' => [['--slug' => 'kathmandu-2', '--owner-email' => 'asha'], 'The owner email field must be a valid email address.'],
    'no owner' => [['--slug' => 'kathmandu-2', '--owner-email' => null], 'The owner email field is required.'],
    'not a colour' => [['--slug' => 'kathmandu-2', '--brand-color' => 'blue'], 'The brand colour is a hex colour like #7A1F2B.'],
]);
