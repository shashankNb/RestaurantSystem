<?php

use App\Enums\OrderStatus;

it('treats completed, rejected and cancelled as final', function () {
    $final = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status): bool => $status->isFinal()));

    expect($final)->toBe([OrderStatus::Completed, OrderStatus::Rejected, OrderStatus::Cancelled]);
});

it('lists the statuses the kitchen still has to act on', function () {
    expect(OrderStatus::active())->toBe([
        OrderStatus::Placed,
        OrderStatus::Accepted,
        OrderStatus::Preparing,
        OrderStatus::Ready,
        OrderStatus::OutForDelivery,
    ]);
});

it('gives every status a label and a colour', function (OrderStatus $status) {
    expect($status->getLabel())->not->toBeEmpty()
        ->and($status->getColor())->not->toBeEmpty();
})->with(OrderStatus::cases());
