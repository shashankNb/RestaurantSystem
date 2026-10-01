<?php

use App\Enums\PromoCodeType;
use App\Enums\RestaurantRole;
use App\Models\Membership;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DemoRestaurantSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(DemoRestaurantSeeder::class);

    $this->restaurant = Restaurant::query()->where('slug', DemoRestaurantSeeder::SLUG)->sole();
});

it('seeds Himalayan Momo House with the project settings', function () {
    expect($this->restaurant)
        ->name->toBe('Himalayan Momo House')
        ->timezone->toBe('Australia/Melbourne')
        ->currency->toBe('AUD')
        ->brand_color->toBe('#7A1F2B')
        ->is_accepting_orders->toBeTrue()
        ->pickup_enabled->toBeTrue()
        ->delivery_enabled->toBeTrue();
});

it('seeds five categories and about twenty items with prices in cents', function () {
    $items = $this->restaurant->menuItems()->get();

    expect($this->restaurant->menuCategories()->pluck('name')->all())
        ->toBe(['Momos', 'Chow mein', 'Thukpa', 'Sides', 'Drinks'])
        ->and($items)->toHaveCount(21)
        ->and($items->every(fn (MenuItem $item): bool => $item->price_cents >= 400 && $item->price_cents <= 2500))->toBeTrue();
});

it('seeds the filling, sauce and spice level groups with their rules', function () {
    $groups = $this->restaurant->modifierGroups()->with('options')->get()->keyBy('name');

    expect($groups)->toHaveCount(3)
        ->and($groups['Choose filling'])->min_select->toBe(1)->max_select->toBe(1)
        ->and($groups['Choose filling']->options->pluck('name')->all())->toBe(['Chicken', 'Pork', 'Vegetable'])
        ->and($groups['Sauce'])->min_select->toBe(0)->max_select->toBe(2)
        ->and($groups['Spice level'])->min_select->toBe(1)->max_select->toBe(1);
});

it('attaches the momo options in order: filling, sauce, spice level', function () {
    $steamed = $this->restaurant->menuItems()->where('name', 'Steamed momo')->sole();

    expect($steamed->modifierGroups->pluck('name')->all())
        ->toBe(['Choose filling', 'Sauce', 'Spice level']);
});

it('keeps every menu row inside the demo restaurant', function () {
    $groups = ModifierGroup::query()->with('options')->get();

    expect(MenuItem::query()->with('category')->get()->every(
        fn (MenuItem $item): bool => $item->restaurant_id === $this->restaurant->id
            && $item->category->restaurant_id === $this->restaurant->id,
    ))->toBeTrue()
        ->and($groups->flatMap->options->every(
            fn ($option): bool => $option->restaurant_id === $this->restaurant->id,
        ))->toBeTrue();
});

it('opens 5pm to 10pm daily and until 11pm on Friday and Saturday', function () {
    $hours = $this->restaurant->openingHours()->orderBy('day_of_week')->get();

    expect($hours)->toHaveCount(7);

    foreach ($hours as $shift) {
        expect($shift->opens_at)->toBe('17:00:00')
            ->and($shift->closes_at)->toBe(in_array($shift->day_of_week, [5, 6], true) ? '23:00:00' : '22:00:00');
    }
});

it('seeds the inner Melbourne delivery zone and one promo code', function () {
    $zone = $this->restaurant->deliveryZones()->sole();
    $promo = $this->restaurant->promoCodes()->sole();

    expect($zone)
        ->postcodes->toBe(['3000', '3006', '3008'])
        ->fee_cents->toBe(600)
        ->min_order_cents->toBe(2500)
        ->and($promo->code)->toBe('MOMO10')
        ->and($promo->type)->toBe(PromoCodeType::Percent)
        ->and($promo->value)->toBe(10)
        ->and($promo->min_order_cents)->toBe(3000);
});

it('creates the demo owner and staff accounts with the documented password', function () {
    $roles = Membership::query()
        ->with('user')
        ->get()
        ->mapWithKeys(fn (Membership $membership): array => [$membership->user->email => $membership->role]);

    expect($roles->all())->toEqual([
        'owner@example.com' => RestaurantRole::Owner,
        'staff@example.com' => RestaurantRole::Staff,
    ]);

    foreach (['owner@example.com', 'staff@example.com', 'customer@example.com'] as $email) {
        expect(Hash::check(DemoRestaurantSeeder::PASSWORD, User::query()->where('email', $email)->sole()->password))->toBeTrue();
    }
});

it('does nothing when run a second time', function () {
    $this->seed(DemoRestaurantSeeder::class);

    expect(Restaurant::query()->count())->toBe(1)
        ->and(MenuItem::query()->count())->toBe(21);
});

it('refuses to seed demo accounts in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    Restaurant::query()->each(fn (Restaurant $restaurant) => $restaurant->forceFill(['slug' => 'renamed'])->save());

    $this->artisan('db:seed', ['--class' => DemoRestaurantSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the demo restaurant')
        ->assertSuccessful();

    expect(Restaurant::query()->where('slug', DemoRestaurantSeeder::SLUG)->exists())->toBeFalse();
});
