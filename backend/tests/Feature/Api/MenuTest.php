<?php

use App\Enums\Allergen;
use App\Enums\DietaryTag;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Tests\Support\MomoMenu;

beforeEach(function () {
    $this->menu = MomoMenu::create();
    $this->url = "/api/v1/restaurants/{$this->menu->restaurant->slug}/menu";
});

it('lists active categories and items in menu order', function () {
    $categories = $this->getJson($this->url)->assertOk()->json('data.categories');

    expect(array_column($categories, 'name'))->toBe(['Momos', 'Drinks'])
        ->and(array_column($categories[0]['items'], 'name'))->toBe(['Steamed momo', 'Kothey momo'])
        ->and(array_column($categories[1]['items'], 'name'))->toBe(['Mango lassi']);
});

it('keeps sold-out items on the menu, marked as sold out', function () {
    $items = collect($this->getJson($this->url)->json('data.categories.0.items'))->keyBy('name');

    expect($items['Steamed momo']['is_available'])->toBeTrue()
        ->and($items['Kothey momo']['is_available'])->toBeFalse();
});

it('describes an item with its option groups in order and their rules', function () {
    $this->menu->steamed->update([
        'description' => 'Juicy dumplings.',
        'dietary_tags' => [DietaryTag::Vegetarian->value, 'no_longer_a_tag'],
        'allergens' => [Allergen::Gluten->value, Allergen::Soy->value],
    ]);

    $item = $this->getJson($this->url)->json('data.categories.0.items.0');

    expect($item)
        ->id->toBe($this->menu->steamed->id)
        ->price_cents->toBe(1790)
        ->description->toBe('Juicy dumplings.')
        ->image_url->toBeNull()
        ->dietary_tags->toBe([['value' => 'vegetarian', 'label' => 'Vegetarian']])
        ->allergens->toBe([['value' => 'gluten', 'label' => 'Gluten'], ['value' => 'soy', 'label' => 'Soy']])
        ->and(array_column($item['modifier_groups'], 'name'))->toBe(['Choose filling', 'Sauce', 'Spice level'])
        ->and(array_column($item['modifier_groups'], 'selection_rule'))->toBe([
            'Required · choose 1',
            'Optional · choose up to 2',
            'Required · choose 1',
        ])
        ->and($item['modifier_groups'][1]['options'])->toBe([
            ['id' => $this->menu->tomatoAchar->id, 'name' => 'Tomato achar', 'price_delta_cents' => 100, 'is_available' => true],
            ['id' => $this->menu->sesameAchar->id, 'name' => 'Sesame achar', 'price_delta_cents' => 100, 'is_available' => true],
            ['id' => $this->menu->chilliOil->id, 'name' => 'Chilli oil', 'price_delta_cents' => 100, 'is_available' => false],
        ]);
});

it('shows a sold-out switch straight away', function () {
    $this->getJson($this->url)->assertJsonPath('data.categories.1.items.0.is_available', true);

    $this->menu->lassi->update(['is_available' => false]);

    $this->getJson($this->url)->assertJsonPath('data.categories.1.items.0.is_available', false);
});

it('leaves out categories with nothing on them', function () {
    MenuCategory::factory()->for($this->menu->restaurant)->create(['name' => 'Desserts']);

    expect(array_column($this->getJson($this->url)->json('data.categories'), 'name'))->not->toContain('Desserts');
});

it('never shows another restaurant’s menu', function () {
    MenuItem::factory()->create(['name' => 'Someone else’s dumpling']);

    $names = collect($this->getJson($this->url)->json('data.categories'))->flatMap(fn (array $category) => array_column($category['items'], 'name'));

    expect($names)->not->toContain('Someone else’s dumpling');
});

it('returns 404 for an unknown restaurant', function () {
    $this->getJson('/api/v1/restaurants/no-such-place/menu')->assertNotFound();
});
