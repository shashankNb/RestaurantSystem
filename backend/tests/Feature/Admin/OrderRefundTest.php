<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\PaymentGateway;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->restaurant = Restaurant::factory()->create();
    $this->owner = User::factory()->ownerOf($this->restaurant)->create();
    /** @var FakePaymentGateway $payments */
    $payments = app(PaymentGateway::class);
    $this->payments = $payments;
});

function ownersOrder(Restaurant $restaurant, string $state): Order
{
    return Order::factory()->for($restaurant)->{$state}()->create(['stripe_payment_intent_id' => 'pi_owner_'.$state]);
}

it('refunds and cancels an unfinished order', function () {
    $order = ownersOrder($this->restaurant, 'accepted');
    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund', data: ['reason' => 'Customer changed their mind'])
        ->assertHasNoActionErrors()
        ->assertNotified('Refund sent to Stripe');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($this->payments->refunds)->toHaveCount(1)
        ->and($order->statusEvents()->latest('id')->first())
        ->user_id->toBe($this->owner->id)
        ->note->toBe('Customer changed their mind');
});

it('refunds a completed order without changing its status', function () {
    $order = ownersOrder($this->restaurant, 'completed');
    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund', data: ['reason' => 'Cold on arrival'])
        ->assertHasNoActionErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded);
});

it('offers a refund only for paid orders', function (?string $state) {
    $factory = Order::factory()->for($this->restaurant);
    $order = ($state === null ? $factory : $factory->{$state}())->create();
    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('refund');
})->with(['unpaid' => [null], 'already refunded' => ['rejected']]);

it('asks for a reason', function () {
    $order = ownersOrder($this->restaurant, 'placed');
    $this->actingAs($this->owner);
    useBackOffice($this->restaurant);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});
