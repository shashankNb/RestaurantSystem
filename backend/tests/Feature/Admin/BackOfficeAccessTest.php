<?php

use App\Models\Restaurant;
use App\Models\User;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create();
    $this->owner = User::factory()->ownerOf($this->restaurant)->create();
});

it('sends the API host root to the back office', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('asks guests to sign in', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('opens the owner’s restaurant', function () {
    $this->actingAs($this->owner)
        ->get("/admin/{$this->restaurant->slug}")
        ->assertOk()
        ->assertSee($this->restaurant->name);
});

it('opens the restaurant settings page for its owner', function () {
    $this->actingAs($this->owner)
        ->get("/admin/{$this->restaurant->slug}/profile")
        ->assertOk()
        ->assertSee('Restaurant settings');
});

it('keeps kitchen staff out of the back office', function () {
    $staff = User::factory()->staffOf($this->restaurant)->create();

    $this->actingAs($staff)->get("/admin/{$this->restaurant->slug}")->assertForbidden();
});

it('keeps customers out of the back office', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('hides other restaurants from an owner', function () {
    $other = Restaurant::factory()->create();

    $this->actingAs($this->owner)
        ->get("/admin/{$other->slug}/menu-items")
        ->assertNotFound();
});

it('hides another restaurant’s order from an owner who guesses its id', function () {
    $other = Restaurant::factory()->create();
    $order = $other->orders()->create([
        'customer_name' => 'Someone Else',
        'fulfilment_type' => 'pickup',
        'subtotal_cents' => 2000,
        'total_cents' => 2000,
        'gst_cents' => 182,
        'idempotency_key' => fake()->uuid(),
        'tracking_token' => str()->random(40),
    ]);

    $this->actingAs($this->owner)
        ->get("/admin/{$this->restaurant->slug}/orders/{$order->id}")
        ->assertNotFound();
});
