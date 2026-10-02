<?php

use App\Filament\Resources\DiningTables\Pages\ManageDiningTables;
use App\Models\DiningTable;
use App\Models\Restaurant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create(['dine_in_enabled' => true]);
    $this->other = Restaurant::factory()->create();

    $this->actingAs(User::factory()->ownerOf($this->restaurant)->create());
});

it('lists only the current restaurant’s tables', function () {
    $mine = DiningTable::factory()->for($this->restaurant)->create(['label' => '4']);
    $theirs = DiningTable::factory()->for($this->other)->create(['label' => '4']);
    useBackOffice($this->restaurant);

    Livewire::test(ManageDiningTables::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('adds numbered tables in one go, skipping ones it already has', function () {
    DiningTable::factory()->for($this->restaurant)->create(['label' => '2']);
    useBackOffice($this->restaurant);

    Livewire::test(ManageDiningTables::class)
        ->callAction('addSeveral', data: ['from' => 1, 'to' => 4])
        ->assertHasNoActionErrors()
        ->assertNotified('Added 3 tables');

    expect($this->restaurant->diningTables()->pluck('label')->sort()->values()->all())->toBe(['1', '2', '3', '4']);
});

it('won’t add a table the restaurant already has, though another restaurant may', function () {
    DiningTable::factory()->for($this->restaurant)->create(['label' => '5']);
    DiningTable::factory()->for($this->other)->create(['label' => '6']);
    useBackOffice($this->restaurant);

    Livewire::test(ManageDiningTables::class)
        ->callAction('create', data: ['label' => '5', 'is_active' => true, 'sort_order' => 0])
        ->assertHasActionErrors(['label']);

    Livewire::test(ManageDiningTables::class)
        ->callAction('create', data: ['label' => '6', 'is_active' => true, 'sort_order' => 0])
        ->assertHasNoActionErrors();

    expect($this->restaurant->diningTables()->where('label', '6')->exists())->toBeTrue();
});

it('prints a QR code for each table taking orders, linking to that table', function () {
    config(['ordering.web_url' => 'https://order.example.test']);
    DiningTable::factory()->for($this->restaurant)->create(['label' => '8']);
    DiningTable::factory()->for($this->restaurant)->inactive()->create(['label' => '9']);

    $this->get("/admin/{$this->restaurant->slug}/dining-tables/print")
        ->assertOk()
        ->assertSee('Table 8')
        ->assertDontSee('Table 9')
        ->assertSee('<svg', escape: false);

    expect(DiningTable::query()->where('label', '8')->sole()->orderingUrl())->toBe('https://order.example.test/table/8');
});

it('links QR codes to the restaurant’s own website when it has one', function () {
    config(['ordering.web_url' => 'https://order.example.test']);
    $this->restaurant->update(['custom_domain' => 'order.kathmandu.test']);
    $table = DiningTable::factory()->for($this->restaurant)->create(['label' => '12']);

    expect($table->fresh()?->orderingUrl())->toBe('https://order.kathmandu.test/table/12');
});
