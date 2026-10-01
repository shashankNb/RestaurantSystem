<?php

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function signInWith(string $email, string $password = 'password'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
        'device_name' => 'Pest',
    ]);
}

it('registers a customer and returns a token that works', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Sam Taylor',
        'email' => '  Sam@Example.com ',
        'phone' => '0491 570 110',
        'password' => 'momos-for-dinner',
        'device_name' => "Sam's iPhone",
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.name', 'Sam Taylor')
        ->assertJsonPath('data.user.email', 'sam@example.com')
        ->assertJsonPath('data.user.phone', '0491 570 110')
        ->assertJsonPath('data.user.restaurants', []);

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'sam@example.com');
});

it('refuses a second account for the same email, whatever its case', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Sam Taylor',
        'email' => 'SAM@example.com',
        'password' => 'momos-for-dinner',
        'device_name' => 'Pest',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'An account with this email already exists. Sign in instead.');
});

it('explains every missing field in the standard error shape', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password', 'device_name']]);
});

it('requires a password of at least 8 characters', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Sam Taylor',
        'email' => 'sam@example.com',
        'password' => 'short',
        'device_name' => 'Pest',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('signs in with the right password', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    signInWith('Sam@Example.com')
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'phone', 'restaurants', 'created_at']]]);
});

it('gives the same answer for a wrong password and an unknown email', function (string $email, string $password) {
    User::factory()->create(['email' => 'sam@example.com']);

    signInWith($email, $password)
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email or password is incorrect.');
})->with([
    'wrong password' => ['sam@example.com', 'not-the-password'],
    'unknown email' => ['nobody@example.com', 'password'],
]);

it('slows down repeated failed sign-ins', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 5) as $attempt) {
        signInWith('sam@example.com', 'guess-'.$attempt)->assertUnprocessable();
    }

    signInWith('sam@example.com', 'guess-6')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Too many attempts. Try again in '));
});

it('signs out by revoking only the current token', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('Phone')->plainTextToken;
    $tablet = $user->createToken('Tablet')->plainTextToken;

    $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
});

it('requires a token for the account endpoints', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('lists the restaurants where a user works', function () {
    $restaurant = Restaurant::factory()->create(['name' => 'Himalayan Momo House', 'slug' => 'himalayan-momo-house']);
    $staff = User::factory()->staffOf($restaurant)->create();

    $this->actingAs($staff)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.restaurants', [[
            'id' => $restaurant->id,
            'slug' => 'himalayan-momo-house',
            'name' => 'Himalayan Momo House',
            'role' => 'staff',
        ]]);
});
