<?php

namespace Tests\Support;

use App\Data\Cart;
use App\Data\CartItem;
use App\Enums\FulfilmentType;
use App\Enums\PromoCodeType;
use App\Models\DeliveryZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\PromoCode;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

/**
 * A small version of the demo menu with known prices, for pricing and menu tests.
 *
 *   Momos      Steamed momo $17.90: filling (required, 1): Chicken, Pork +$1, Vegetable
 *                                   sauce (optional, up to 2): Tomato achar +$1, Sesame achar +$1, Chilli oil +$1 (sold out)
 *                                   spice (required, 1): Mild, Hot
 *              Kothey momo $18.90, sold out; Retired momo $9.00, inactive
 *   Drinks     Mango lassi $7.50
 *   Secret     (inactive category) Secret dumpling $1.00
 *
 * Open 5 pm to 10 pm every day. Delivery to 3000, 3006 and 3008 for $6.00, minimum $25.00,
 * 45 minutes. Promo codes MOMO10 (10% off $30 or more) and FIVEOFF ($5 off).
 */
final class MomoMenu
{
    public Restaurant $restaurant;

    public MenuItem $steamed;

    public MenuItem $kothey;

    public MenuItem $retired;

    public MenuItem $lassi;

    public MenuItem $secret;

    public ModifierGroup $filling;

    public ModifierGroup $sauce;

    public ModifierGroup $spice;

    public ModifierOption $chicken;

    public ModifierOption $pork;

    public ModifierOption $vegetable;

    public ModifierOption $tomatoAchar;

    public ModifierOption $sesameAchar;

    public ModifierOption $chilliOil;

    public ModifierOption $mild;

    public ModifierOption $hot;

    public DeliveryZone $zone;

    public PromoCode $momo10;

    public PromoCode $fiveOff;

    public static function create(): self
    {
        $menu = new self;
        $menu->restaurant = Restaurant::factory()->create([
            'timezone' => 'Australia/Melbourne',
            'default_prep_minutes' => 20,
        ]);

        foreach (range(0, 6) as $day) {
            $menu->restaurant->openingHours()->create(['day_of_week' => $day, 'opens_at' => '17:00:00', 'closes_at' => '22:00:00']);
        }

        $momos = MenuCategory::factory()->for($menu->restaurant)->create(['name' => 'Momos', 'sort_order' => 1]);
        $drinks = MenuCategory::factory()->for($menu->restaurant)->create(['name' => 'Drinks', 'sort_order' => 2]);
        $secret = MenuCategory::factory()->for($menu->restaurant)->create(['name' => 'Secret', 'sort_order' => 3, 'is_active' => false]);

        $menu->filling = $menu->group('Choose filling', 1, 1, 1);
        $menu->chicken = $menu->option($menu->filling, 'Chicken', 0);
        $menu->pork = $menu->option($menu->filling, 'Pork', 100);
        $menu->vegetable = $menu->option($menu->filling, 'Vegetable', 0);

        $menu->sauce = $menu->group('Sauce', 0, 2, 2);
        $menu->tomatoAchar = $menu->option($menu->sauce, 'Tomato achar', 100);
        $menu->sesameAchar = $menu->option($menu->sauce, 'Sesame achar', 100);
        $menu->chilliOil = $menu->option($menu->sauce, 'Chilli oil', 100, available: false);

        $menu->spice = $menu->group('Spice level', 1, 1, 3);
        $menu->mild = $menu->option($menu->spice, 'Mild', 0);
        $menu->hot = $menu->option($menu->spice, 'Hot', 0);

        $menu->steamed = $menu->item($momos, 'Steamed momo', 1790, sortOrder: 1);
        $menu->steamed->modifierGroups()->attach([
            $menu->filling->id => ['sort_order' => 1],
            $menu->sauce->id => ['sort_order' => 2],
            $menu->spice->id => ['sort_order' => 3],
        ]);
        $menu->kothey = $menu->item($momos, 'Kothey momo', 1890, sortOrder: 2, available: false);
        $menu->kothey->modifierGroups()->attach([
            $menu->filling->id => ['sort_order' => 1],
            $menu->spice->id => ['sort_order' => 2],
        ]);
        $menu->retired = $menu->item($momos, 'Retired momo', 900, sortOrder: 3, active: false);
        $menu->lassi = $menu->item($drinks, 'Mango lassi', 750, sortOrder: 1);
        $menu->secret = $menu->item($secret, 'Secret dumpling', 100, sortOrder: 1);

        $menu->zone = DeliveryZone::factory()->for($menu->restaurant)->create();

        $menu->momo10 = PromoCode::factory()->for($menu->restaurant)->create([
            'code' => 'MOMO10', 'type' => PromoCodeType::Percent, 'value' => 10, 'min_order_cents' => 3000,
            'starts_at' => null, 'ends_at' => null, 'max_uses' => null, 'is_active' => true,
        ]);
        $menu->fiveOff = PromoCode::factory()->for($menu->restaurant)->create([
            'code' => 'FIVEOFF', 'type' => PromoCodeType::Fixed, 'value' => 500, 'min_order_cents' => 0,
            'starts_at' => null, 'ends_at' => null, 'max_uses' => null, 'is_active' => true,
        ]);

        $menu->restaurant->refresh();

        return $menu;
    }

    /**
     * A steamed momo with the usual required choices: chicken, mild.
     *
     * @param  list<int>|null  $optionIds
     */
    public function steamedLine(int $quantity = 1, ?array $optionIds = null): CartItem
    {
        return new CartItem($this->steamed->id, $quantity, $optionIds ?? [$this->chicken->id, $this->mild->id]);
    }

    /**
     * An order request as the app sends it: two chicken momos, mild, for pickup, as soon as
     * possible. Overrides replace top-level keys.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function orderPayload(array $overrides = []): array
    {
        return array_replace([
            'fulfilment_type' => 'pickup',
            'items' => [
                ['menu_item_id' => $this->steamed->id, 'quantity' => 2, 'modifier_option_ids' => [$this->chicken->id, $this->mild->id]],
            ],
            'customer' => ['name' => 'Sam Taylor', 'phone' => '0491 570 110', 'email' => 'sam@example.com'],
            'notes' => 'Ring when you’re here',
        ], $overrides);
    }

    /**
     * The same for delivery to Southbank (3006).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function deliveryPayload(array $overrides = []): array
    {
        return $this->orderPayload([
            'fulfilment_type' => 'delivery',
            'postcode' => '3006',
            'delivery' => ['line1' => '12 Southbank Boulevard', 'line2' => 'Apartment 1204', 'suburb' => 'Southbank', 'state' => 'VIC', 'instructions' => 'Buzz 1204'],
            ...$overrides,
        ]);
    }

    /**
     * @param  list<CartItem>  $items
     */
    public function cart(
        array $items,
        FulfilmentType $type = FulfilmentType::Pickup,
        ?string $postcode = null,
        ?CarbonImmutable $scheduledFor = null,
        ?string $promoCode = null,
    ): Cart {
        return new Cart($type, $items, $postcode, $scheduledFor, $promoCode);
    }

    private function group(string $name, int $min, int $max, int $sortOrder): ModifierGroup
    {
        return ModifierGroup::factory()->for($this->restaurant)->create([
            'name' => $name, 'min_select' => $min, 'max_select' => $max, 'sort_order' => $sortOrder,
        ]);
    }

    private function option(ModifierGroup $group, string $name, int $priceDelta, bool $available = true): ModifierOption
    {
        return ModifierOption::factory()->for($group, 'group')->create([
            'name' => $name, 'price_delta_cents' => $priceDelta, 'is_available' => $available,
            'sort_order' => $group->options()->count(),
        ]);
    }

    private function item(MenuCategory $category, string $name, int $price, int $sortOrder, bool $available = true, bool $active = true): MenuItem
    {
        return MenuItem::factory()->for($category, 'category')->create([
            'name' => $name, 'price_cents' => $price, 'sort_order' => $sortOrder,
            'is_available' => $available, 'is_active' => $active, 'image' => null,
        ]);
    }
}
