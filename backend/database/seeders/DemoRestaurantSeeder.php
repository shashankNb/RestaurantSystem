<?php

namespace Database\Seeders;

use App\Enums\Allergen;
use App\Enums\DietaryTag;
use App\Enums\PromoCodeType;
use App\Enums\RestaurantRole;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Himalayan Momo House, the fictional demo restaurant. Demo credentials are
 * listed in docs/SETUP.md.
 */
class DemoRestaurantSeeder extends Seeder
{
    public const SLUG = 'himalayan-momo-house';

    public const PASSWORD = 'password';

    /**
     * Modifier groups, keyed by the handle the menu below refers to them by.
     *
     * @var array<string, array{name: string, min: int, max: int, options: list<array{0: string, 1: int}>}>
     */
    private const MODIFIER_GROUPS = [
        'filling' => ['name' => 'Choose filling', 'min' => 1, 'max' => 1, 'options' => [
            ['Chicken', 0],
            ['Pork', 100],
            ['Vegetable', 0],
        ]],
        'sauce' => ['name' => 'Sauce', 'min' => 0, 'max' => 2, 'options' => [
            ['Tomato achar', 100],
            ['Sesame achar', 100],
            ['Chilli oil', 100],
            ['Garlic yoghurt', 100],
        ]],
        'spice' => ['name' => 'Spice level', 'min' => 1, 'max' => 1, 'options' => [
            ['Mild', 0],
            ['Medium', 0],
            ['Hot', 0],
            ['Nepali hot', 0],
        ]],
    ];

    /**
     * @var list<array{name: string, description: ?string, items: list<array{0: string, 1: string, 2: int, 3: list<string>, 4: list<DietaryTag>, 5: list<Allergen>}>}>
     */
    private const MENU = [
        [
            'name' => 'Momos',
            'description' => 'Ten hand-folded dumplings per serve, made fresh every afternoon.',
            'items' => [
                ['Steamed momo', 'Juicy dumplings steamed to order and served with tomato achar.', 1790, ['filling', 'sauce', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Fried momo', 'Steamed, then fried until the skins blister and crisp.', 1890, ['filling', 'sauce', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Jhol momo', 'Steamed momos in a warm, tangy broth of roasted tomato, sesame and timur.', 1990, ['filling', 'sauce', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy, Allergen::Sesame]],
                ['Kothey momo', 'Pan-fried on one side: soft on top, golden and crunchy underneath.', 1890, ['filling', 'sauce', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Chilli momo', 'Fried momos tossed with capsicum, onion and a sticky chilli sauce.', 2090, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Tandoori momo', 'Marinated in spiced yoghurt, then charred in a hot oven.', 2090, ['filling', 'sauce', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy, Allergen::Milk]],
            ],
        ],
        [
            'name' => 'Chow mein',
            'description' => 'Wok-tossed noodles, cooked to order.',
            'items' => [
                ['Chow mein', 'Stir-fried noodles with cabbage, carrot and spring onion.', 1690, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy, Allergen::Egg]],
                ['Chilli chow mein', 'Our chow mein with fresh chilli and a hit of timur pepper.', 1790, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy, Allergen::Egg]],
                ['Keema noodles', 'Noodles tossed with spiced chicken mince, egg and spring onion.', 1790, ['spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy, Allergen::Egg]],
            ],
        ],
        [
            'name' => 'Thukpa',
            'description' => 'Noodle soups from the Himalayan foothills, built on a slow-cooked broth.',
            'items' => [
                ['Thukpa', 'Hand-pulled noodles and vegetables in a clear, gingery broth.', 1890, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Thenthuk', 'Hand-torn flat noodles in a rich broth with garlic and greens.', 1890, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat, Allergen::Soy]],
                ['Sherpa stew', 'A hearty stew of potato, vegetables and torn noodles.', 1790, ['filling', 'spice'], [], [Allergen::Gluten, Allergen::Wheat]],
            ],
        ],
        [
            'name' => 'Sides',
            'description' => 'Small plates to share.',
            'items' => [
                ['Aloo sadheko', 'Spiced potato salad with sesame, timur and fresh coriander.', 950, ['spice'], [DietaryTag::Vegan, DietaryTag::GlutenFree], [Allergen::Sesame]],
                ['Chicken choila', 'Smoky grilled chicken marinated in mustard oil, garlic and chilli.', 1490, ['spice'], [DietaryTag::GlutenFree, DietaryTag::DairyFree], []],
                ['Sel roti', 'Two crisp rings of lightly sweet rice bread.', 750, [], [DietaryTag::Vegetarian], [Allergen::Milk]],
                ['Chatpate', 'Puffed rice tossed with onion, tomato, lemon, peanuts and chilli.', 850, ['spice'], [DietaryTag::Vegan], [Allergen::Peanut]],
                ['Timur chips', 'Crinkle-cut chips seasoned with timur pepper salt.', 790, ['sauce'], [DietaryTag::Vegan, DietaryTag::GlutenFree], []],
            ],
        ],
        [
            'name' => 'Drinks',
            'description' => null,
            'items' => [
                ['Masala chiya', 'Milky Nepali tea brewed with ginger, cardamom and cinnamon.', 550, [], [DietaryTag::Vegetarian, DietaryTag::GlutenFree], [Allergen::Milk]],
                ['Mango lassi', 'Mango blended with yoghurt and a pinch of cardamom.', 750, [], [DietaryTag::Vegetarian, DietaryTag::GlutenFree], [Allergen::Milk]],
                ['Lemon soda', 'Fresh lemon juice, soda water and a pinch of black salt.', 500, [], [DietaryTag::Vegan, DietaryTag::GlutenFree], []],
                ['Coca-Cola', '375 ml can.', 400, [], [DietaryTag::Vegan, DietaryTag::GlutenFree], []],
            ],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipped the demo restaurant: its accounts use a public password.');

            return;
        }

        $existing = Restaurant::query()->where('slug', self::SLUG)->first();

        if ($existing !== null) {
            $changed = false;

            // Added after the first release: give an earlier demo restaurant its tables.
            if ($existing->diningTables()->doesntExist()) {
                $this->seedTables($existing);
                $existing->update(['dine_in_enabled' => true]);
                $this->command->info('Added tables 1–12 to the demo restaurant and turned on dine in.');
                $changed = true;
            }

            // Stripe keys moved from .env to each restaurant's settings.
            if (! $existing->acceptsPayments() && ($keys = $this->stripeKeys()) !== []) {
                $existing->update($keys);
                $this->command->info('Gave the demo restaurant the Stripe keys from .env.');
                $changed = true;
            }

            if (! $changed) {
                $this->command->info('The demo restaurant is already seeded.');
            }

            return;
        }

        DB::transaction(function (): void {
            $restaurant = Restaurant::create([
                'name' => 'Himalayan Momo House',
                'slug' => self::SLUG,
                'description' => 'Hand-folded momos, chow mein and thukpa from a family kitchen in Melbourne. Order direct for pickup or delivery.',
                'timezone' => 'Australia/Melbourne',
                'currency' => 'AUD',
                // ACMA-reserved fictitious numbers.
                'phone' => '03 5550 0142',
                'email' => 'hello@example-restaurant.com.au',
                'address' => [
                    'line1' => 'Shop 3, 210 Little Lonsdale Street',
                    'line2' => null,
                    'suburb' => 'Melbourne',
                    'state' => 'VIC',
                    'postcode' => '3000',
                    'country' => 'AU',
                ],
                'abn' => '12 345 678 901',
                'brand_color' => '#7A1F2B',
                'is_accepting_orders' => true,
                'pickup_enabled' => true,
                'delivery_enabled' => true,
                'dine_in_enabled' => true,
                'default_prep_minutes' => 20,
                'auto_reject_minutes' => 10,
                ...$this->stripeKeys(),
            ]);

            $this->seedAccounts($restaurant);
            $this->seedHours($restaurant);
            $this->seedTables($restaurant);
            $this->seedMenu($restaurant, $this->seedModifierGroups($restaurant));

            $restaurant->deliveryZones()->create([
                'name' => 'Inner Melbourne',
                'postcodes' => ['3000', '3006', '3008'],
                'fee_cents' => 600,
                'min_order_cents' => 2500,
                'estimated_minutes' => 45,
                'is_active' => true,
            ]);

            $restaurant->promoCodes()->create([
                'code' => 'MOMO10',
                'type' => PromoCodeType::Percent,
                'value' => 10,
                'min_order_cents' => 3000,
                'is_active' => true,
            ]);
        });
    }

    /** Tables 1 to 12, for dine-in orders. */
    /**
     * The demo restaurant's own Stripe keys, from STRIPE_KEY, STRIPE_SECRET and
     * STRIPE_WEBHOOK_SECRET in .env (other restaurants' owners enter theirs in the back
     * office). Only keys that look right are used: pasted the wrong way round, Stripe
     * would refuse every payment.
     *
     * @return array<string, string>
     */
    private function stripeKeys(): array
    {
        $publishable = (string) config('services.stripe.key');
        $secret = (string) config('services.stripe.secret');
        $webhookSecret = (string) config('services.stripe.webhook_secret');

        if ($publishable === '' && $secret === '') {
            return [];
        }

        if (! str_starts_with($publishable, 'pk_') || preg_match('/^(sk|rk)_/', $secret) !== 1) {
            $this->command->warn('Skipped the Stripe keys in .env: STRIPE_KEY should be the publishable key (pk_…) and STRIPE_SECRET the secret key (sk_…). Fix them and seed again, or enter the keys in the back office (Restaurant settings → Payments).');

            return [];
        }

        return array_filter([
            'stripe_publishable_key' => $publishable,
            'stripe_secret_key' => $secret,
            'stripe_webhook_secret' => $webhookSecret,
        ], fn (string $value): bool => $value !== '');
    }

    private function seedTables(Restaurant $restaurant): void
    {
        foreach (range(1, 12) as $number) {
            $restaurant->diningTables()->create(['label' => (string) $number, 'is_active' => true, 'sort_order' => 0]);
        }
    }

    private function seedAccounts(Restaurant $restaurant): void
    {
        $accounts = [
            ['Demo Owner', 'owner@example.com', '0491 570 156', RestaurantRole::Owner],
            ['Demo Staff', 'staff@example.com', '0491 570 157', RestaurantRole::Staff],
            ['Demo Customer', 'customer@example.com', '0491 570 158', null],
        ];

        foreach ($accounts as [$name, $email, $phone, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'phone' => $phone, 'password' => self::PASSWORD],
            );

            if ($role !== null) {
                $restaurant->memberships()->create(['user_id' => $user->id, 'role' => $role]);
            } else {
                $user->addresses()->firstOrCreate(['label' => 'Home'], [
                    'line1' => '15 Riverside Quay',
                    'suburb' => 'Southbank',
                    'state' => 'VIC',
                    'postcode' => '3006',
                    'delivery_instructions' => 'Buzz apartment 1204.',
                ]);
            }
        }
    }

    private function seedHours(Restaurant $restaurant): void
    {
        // 5pm–10pm daily, until 11pm on Friday (5) and Saturday (6).
        foreach (range(0, 6) as $day) {
            $restaurant->openingHours()->create([
                'day_of_week' => $day,
                'opens_at' => '17:00:00',
                'closes_at' => in_array($day, [5, 6], true) ? '23:00:00' : '22:00:00',
            ]);
        }

        $restaurant->specialHours()->create([
            'date' => '2026-12-25',
            'is_closed' => true,
            'note' => 'Christmas Day',
        ]);
    }

    /**
     * @return array<string, ModifierGroup>
     */
    private function seedModifierGroups(Restaurant $restaurant): array
    {
        $groups = [];

        foreach (self::MODIFIER_GROUPS as $handle => $definition) {
            $group = $restaurant->modifierGroups()->create([
                'name' => $definition['name'],
                'min_select' => $definition['min'],
                'max_select' => $definition['max'],
                'sort_order' => count($groups),
            ]);

            foreach ($definition['options'] as $optionIndex => [$name, $priceDelta]) {
                $group->options()->create([
                    'name' => $name,
                    'price_delta_cents' => $priceDelta,
                    'sort_order' => $optionIndex,
                ]);
            }

            $groups[$handle] = $group;
        }

        return $groups;
    }

    /**
     * @param  array<string, ModifierGroup>  $groups
     */
    private function seedMenu(Restaurant $restaurant, array $groups): void
    {
        foreach (self::MENU as $categoryIndex => $categoryDefinition) {
            $category = $restaurant->menuCategories()->create([
                'name' => $categoryDefinition['name'],
                'description' => $categoryDefinition['description'],
                'sort_order' => $categoryIndex,
            ]);

            foreach ($categoryDefinition['items'] as $itemIndex => [$name, $description, $price, $groupHandles, $dietaryTags, $allergens]) {
                $item = $category->items()->create([
                    'name' => $name,
                    'description' => $description,
                    'price_cents' => $price,
                    'dietary_tags' => array_map(fn (DietaryTag $tag): string => $tag->value, $dietaryTags),
                    'allergens' => array_map(fn (Allergen $allergen): string => $allergen->value, $allergens),
                    'sort_order' => $itemIndex,
                ]);

                foreach ($groupHandles as $position => $handle) {
                    $item->modifierGroups()->attach($groups[$handle], ['sort_order' => $position]);
                }
            }
        }
    }
}
