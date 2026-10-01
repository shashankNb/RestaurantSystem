<?php

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\InvalidOrderTransition;
use App\Services\OrderService;
use Carbon\CarbonImmutable;

/**
 * The only transitions the lifecycle allows, per fulfilment type. Everything else is refused.
 *
 * @return list<OrderStatus>
 */
function allowedNext(OrderStatus $from, FulfilmentType $type): array
{
    $delivery = $type === FulfilmentType::Delivery;

    return match ($from) {
        OrderStatus::PendingPayment => [OrderStatus::Placed, OrderStatus::Cancelled],
        OrderStatus::Placed => [OrderStatus::Accepted, OrderStatus::Rejected, OrderStatus::Cancelled],
        OrderStatus::Accepted => [OrderStatus::Preparing, OrderStatus::Cancelled],
        OrderStatus::Preparing => [OrderStatus::Ready, OrderStatus::Cancelled],
        OrderStatus::Ready => $delivery
            ? [OrderStatus::OutForDelivery, OrderStatus::Cancelled]
            : [OrderStatus::Completed, OrderStatus::Cancelled],
        OrderStatus::OutForDelivery => [OrderStatus::Completed, OrderStatus::Cancelled],
        OrderStatus::Completed, OrderStatus::Rejected, OrderStatus::Cancelled => [],
    };
}

function orderIn(OrderStatus $status, FulfilmentType $type = FulfilmentType::Pickup): Order
{
    $order = Order::factory()->for(Restaurant::factory())->placed()->create(['fulfilment_type' => $type]);

    $order->forceFill(['status' => $status])->save();

    return $order;
}

it('allows exactly the lifecycle’s transitions', function (OrderStatus $from, FulfilmentType $type) {
    $order = orderIn($from, $type);
    $service = app(OrderService::class);

    $allowed = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $to): bool => $service->canTransition($order, $to)));

    expect($allowed)->toBe(allowedNext($from, $type));
})->with(OrderStatus::cases())->with([FulfilmentType::Pickup, FulfilmentType::Delivery]);

it('refuses a forbidden move and says why', function () {
    $staff = User::factory()->create();
    $order = orderIn(OrderStatus::Ready);

    expect(fn () => app(OrderService::class)->advance($order, OrderStatus::Preparing, $staff))
        ->toThrow(InvalidOrderTransition::class, 'An order that’s ready can’t be marked preparing.');

    expect($order->refresh()->status)->toBe(OrderStatus::Ready)
        ->and($order->statusEvents()->where('to_status', OrderStatus::Preparing)->exists())->toBeFalse();
});

it('won’t change a finished order', function () {
    $staff = User::factory()->create();
    $order = orderIn(OrderStatus::Completed);

    expect(fn () => app(OrderService::class)->advance($order, OrderStatus::Cancelled, $staff))
        ->toThrow(InvalidOrderTransition::class, 'This order is already completed, so it can’t change any more.');
});

it('records each change, who made it, and its time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00', 'Australia/Melbourne'));
    $staff = User::factory()->create();
    $order = orderIn(OrderStatus::Placed);
    $service = app(OrderService::class);

    $service->accept($order, 15, $staff);
    $service->advance($order, OrderStatus::Preparing, $staff);
    $this->travel(12)->minutes();
    $service->advance($order, OrderStatus::Ready, $staff);
    $service->advance($order, OrderStatus::Completed, $staff, 'Collected.');

    $order->refresh();
    $events = $order->statusEvents()->get();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->prep_minutes)->toBe(15)
        ->and($order->estimated_ready_at?->toIso8601ZuluString())->toBe('2026-10-05T07:15:00Z')
        ->and($order->ready_at?->toIso8601ZuluString())->toBe('2026-10-05T07:12:00Z')
        ->and($order->completed_at)->not->toBeNull()
        ->and($events->map(fn ($event) => $event->to_status)->all())->toBe([OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Completed])
        ->and($events->pluck('from_status')->all())->toBe([OrderStatus::Placed, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready])
        ->and($events->pluck('user_id')->unique()->all())->toBe([$staff->id])
        ->and($events->first()?->note)->toBe('Ready in 15 minutes.')
        ->and($events->last()?->note)->toBe('Collected.');
});

it('keeps a scheduled order’s promised time when the kitchen accepts it early', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Australia/Melbourne'));
    $order = orderIn(OrderStatus::Placed);
    $order->forceFill(['scheduled_for' => CarbonImmutable::parse('2026-10-05 18:30', 'Australia/Melbourne')->utc()])->save();

    app(OrderService::class)->accept($order, 20, User::factory()->create());

    expect($order->refresh()->estimated_ready_at?->toIso8601ZuluString())->toBe('2026-10-05T07:30:00Z');
});
