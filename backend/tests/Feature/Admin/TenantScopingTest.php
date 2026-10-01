<?php

use App\Enums\RestaurantRole;
use App\Filament\Resources\DeliveryZones\Pages\ManageDeliveryZones;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Resources\MenuCategories\Pages\ManageMenuCategories;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\ModifierGroups\Pages\CreateModifierGroup;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PromoCodes\Pages\ManagePromoCodes;
use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/*
 * Other restaurants' rows are created before the back office is opened:
 * while it is open, Filament assigns every new tenant-owned row to the
 * current restaurant, exactly as it does for an owner's own edits.
 */

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create();
    $this->other = Restaurant::factory()->create();

    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
});

it('lists only the current restaurant’s menu items', function () {
    $mine = MenuItem::factory()->for(MenuCategory::factory()->for($this->restaurant), 'category')->create();
    $theirs = MenuItem::factory()->for(MenuCategory::factory()->for($this->other), 'category')->create();
    useBackOffice($this->restaurant);

    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('lists only the current restaurant’s orders', function () {
    $mine = Order::factory()->for($this->restaurant)->placed()->create();
    $theirs = Order::factory()->for($this->other)->placed()->create();
    useBackOffice($this->restaurant);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('lists only the current restaurant’s delivery zones and promo codes', function () {
    $myZone = DeliveryZone::factory()->for($this->restaurant)->create();
    $theirZone = DeliveryZone::factory()->for($this->other)->create();
    $myCode = PromoCode::factory()->for($this->restaurant)->create();
    $theirCode = PromoCode::factory()->for($this->other)->create();
    useBackOffice($this->restaurant);

    Livewire::test(ManageDeliveryZones::class)
        ->assertCanSeeTableRecords([$myZone])
        ->assertCanNotSeeTableRecords([$theirZone]);

    Livewire::test(ManagePromoCodes::class)
        ->assertCanSeeTableRecords([$myCode])
        ->assertCanNotSeeTableRecords([$theirCode]);
});

it('adds new categories to the current restaurant, at the end of the menu', function () {
    MenuCategory::factory()->for($this->restaurant)->create(['sort_order' => 4]);
    useBackOffice($this->restaurant);

    Livewire::test(ManageMenuCategories::class)
        ->callAction('create', data: ['name' => 'Specials', 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(MenuCategory::query()->where('name', 'Specials')->sole())
        ->restaurant_id->toBe($this->restaurant->id)
        ->sort_order->toBe(5);
});

it('saves a menu item price entered in dollars as cents', function () {
    $category = MenuCategory::factory()->for($this->restaurant)->create();
    useBackOffice($this->restaurant);

    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'name' => 'Buff momo',
            'category_id' => $category->id,
            'price_cents' => '18.50',
            'is_available' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MenuItem::query()->where('name', 'Buff momo')->sole())
        ->price_cents->toBe(1850)
        ->restaurant_id->toBe($this->restaurant->id);
});

it('does not offer another restaurant’s option groups or categories on a menu item', function () {
    $myCategory = MenuCategory::factory()->for($this->restaurant)->create();
    $theirCategory = MenuCategory::factory()->for($this->other)->create();
    $theirGroup = ModifierGroup::factory()->for($this->other)->create();
    useBackOffice($this->restaurant);

    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'name' => 'Sneaky item',
            'category_id' => $myCategory->id,
            'price_cents' => '10.00',
            'modifierGroups' => [$theirGroup->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['modifierGroups']);

    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'name' => 'Sneaky item',
            'category_id' => $theirCategory->id,
            'price_cents' => '10.00',
        ])
        ->call('create')
        ->assertHasFormErrors(['category_id']);

    expect(MenuItem::query()->where('name', 'Sneaky item')->exists())->toBeFalse();
});

it('creates an option group with its options in the current restaurant', function () {
    useBackOffice($this->restaurant);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateModifierGroup::class)
        ->fillForm([
            'name' => 'Extras',
            'min_select' => 0,
            'max_select' => 2,
            'options' => [
                ['name' => 'Extra achar', 'price_delta_cents' => '1.00', 'is_available' => true],
                ['name' => 'No onion', 'price_delta_cents' => '0', 'is_available' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $group = ModifierGroup::query()->where('name', 'Extras')->sole();

    expect($group->restaurant_id)->toBe($this->restaurant->id)
        ->and($group->options->pluck('price_delta_cents', 'name')->all())->toBe(['Extra achar' => 100, 'No onion' => 0])
        ->and($group->options->pluck('restaurant_id')->unique()->all())->toBe([$this->restaurant->id]);
});

it('refuses an option group that requires more choices than it has options', function () {
    useBackOffice($this->restaurant);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateModifierGroup::class)
        ->fillForm([
            'name' => 'Impossible',
            'min_select' => 2,
            'max_select' => 2,
            'options' => [
                ['name' => 'Only one', 'price_delta_cents' => '0', 'is_available' => true],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['min_select']);

    $undoRepeaterFake();
});

it('allows the same promo code at two restaurants but not twice at one', function () {
    PromoCode::factory()->for($this->other)->create(['code' => 'WELCOME']);
    useBackOffice($this->restaurant);

    Livewire::test(ManagePromoCodes::class)
        ->callAction('create', data: ['code' => 'welcome', 'type' => 'percent', 'value' => 15, 'min_order_cents' => '0', 'is_active' => true])
        ->assertHasNoActionErrors()
        ->callAction('create', data: ['code' => 'WELCOME', 'type' => 'percent', 'value' => 5, 'min_order_cents' => '0', 'is_active' => true])
        ->assertHasActionErrors(['code']);

    $promo = $this->restaurant->promoCodes()->sole();

    expect($promo->code)->toBe('WELCOME')
        ->and($promo->value)->toBe(15);
});

it('stores a fixed promo discount entered in dollars as cents', function () {
    useBackOffice($this->restaurant);

    Livewire::test(ManagePromoCodes::class)
        ->callAction('create', data: ['code' => 'FIVEOFF', 'type' => 'fixed', 'value' => '5.00', 'min_order_cents' => '30.00', 'is_active' => true])
        ->assertHasNoActionErrors();

    $promo = $this->restaurant->promoCodes()->sole();

    expect($promo->value)->toBe(500)
        ->and($promo->min_order_cents)->toBe(3000);
});

it('keeps the owner role on at least one person', function () {
    $ownerMembership = $this->restaurant->memberships()->sole();

    expect(MembershipResource::isLastOwner($ownerMembership))->toBeTrue();

    User::factory()->ownerOf($this->restaurant)->create();

    expect(MembershipResource::isLastOwner($ownerMembership->fresh()))->toBeFalse()
        ->and($this->restaurant->memberships()->where('role', RestaurantRole::Owner)->count())->toBe(2);
});
