<?php

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PushToken;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\AccountService;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create();
    $this->customer = User::factory()->create(['email' => 'sam@example.com']);
});

it('deletes a customer’s account and anonymises their past orders', function () {
    $order = Order::factory()->for($this->restaurant)->delivery()->completed()->create([
        'user_id' => $this->customer->id,
        'customer_name' => 'Sam Taylor',
        'customer_email' => 'sam@example.com',
        'delivery_line1' => '12 Southbank Boulevard',
        'delivery_line2' => 'Apartment 1204',
        'delivery_postcode' => '3006',
        'delivery_suburb' => 'Southbank',
        'delivery_instructions' => 'Buzz 1204, then take the lift',
        'notes' => 'Sam’s birthday dinner',
        'push_token' => 'ExponentPushToken[abc]',
    ]);
    CustomerAddress::factory()->for($this->customer)->create();
    PushToken::factory()->for($this->restaurant)->for($this->customer)->create();
    $token = $this->customer->createToken('Phone')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/me')->assertNoContent();

    $order->refresh();

    expect(User::query()->whereKey($this->customer->id)->exists())->toBeFalse()
        ->and($order->user_id)->toBeNull()
        ->and($order->customer_name)->toBe(AccountService::DELETED_CUSTOMER_NAME)
        ->and($order->customer_phone)->toBeNull()
        ->and($order->customer_email)->toBeNull()
        ->and($order->delivery_line1)->toBeNull()
        ->and($order->delivery_line2)->toBeNull()
        ->and($order->delivery_instructions)->toBeNull()
        ->and($order->notes)->toBeNull()
        ->and($order->push_token)->toBeNull()
        // The order itself remains a complete financial record.
        ->and($order->delivery_suburb)->toBe('Southbank')
        ->and($order->delivery_postcode)->toBe('3006')
        ->and($order->total_cents)->toBeGreaterThan(0)
        ->and(CustomerAddress::query()->count())->toBe(0)
        ->and(PushToken::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('leaves other customers’ orders alone', function () {
    $someoneElse = User::factory()->create();
    $theirOrder = Order::factory()->for($this->restaurant)->completed()->create([
        'user_id' => $someoneElse->id,
        'customer_name' => 'Alex Chen',
    ]);

    $this->actingAs($this->customer)->deleteJson('/api/v1/me')->assertNoContent();

    expect($theirOrder->refresh())
        ->user_id->toBe($someoneElse->id)
        ->customer_name->toBe('Alex Chen');
});

it('refuses to delete a staff or owner account from the app', function (string $role) {
    $user = $role === 'owner'
        ? User::factory()->ownerOf($this->restaurant)->create()
        : User::factory()->staffOf($this->restaurant)->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'remove your access in the back office'));

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
})->with(['staff', 'owner']);

it('requires signing in', function () {
    $this->deleteJson('/api/v1/me')->assertUnauthorized();
});
