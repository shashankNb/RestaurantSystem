<?php

namespace App\Support;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * What a customer is told when their order changes, in push notifications. Plain words,
 * the same action names as the app.
 */
final class OrderMessages
{
    /**
     * @return array{title: string, body: string}|null null when there's nothing to say
     */
    public static function forCustomer(Order $order): ?array
    {
        $number = $order->display_number;

        if ($number === null) {
            return null;
        }

        $restaurant = $order->restaurant->name;

        return match ($order->status) {
            OrderStatus::Placed => [
                'title' => "Order {$number} is in",
                'body' => "{$restaurant} has your order. We’ll let you know when the kitchen accepts it.",
            ],
            OrderStatus::Accepted => [
                'title' => "Order {$number} accepted",
                'body' => $order->estimated_ready_at === null
                    ? 'The kitchen has your order.'
                    : 'It should be ready around '.LocalTime::time(CarbonImmutable::parse($order->estimated_ready_at)->setTimezone($order->restaurant->timezone)).'.',
            ],
            OrderStatus::Preparing => [
                'title' => "Order {$number} is cooking",
                'body' => 'The kitchen has started on your order.',
            ],
            OrderStatus::Ready => match ($order->fulfilment_type) {
                FulfilmentType::Delivery => ['title' => "Order {$number} is packed", 'body' => 'It’s ready and waiting for the driver.'],
                FulfilmentType::DineIn => ['title' => "Order {$number} is ready", 'body' => "We’re bringing it to table {$order->table_label}."],
                FulfilmentType::Pickup => ['title' => "Order {$number} is ready", 'body' => "Come and collect it from {$restaurant}."],
            },
            OrderStatus::OutForDelivery => [
                'title' => "Order {$number} is on its way",
                'body' => 'The driver has your order.',
            ],
            OrderStatus::Completed => [
                'title' => 'Enjoy your meal',
                'body' => "Thanks for ordering from {$restaurant}.",
            ],
            OrderStatus::Rejected => [
                'title' => "We can’t make order {$number}",
                'body' => rtrim((string) $order->rejection_reason, '.').'. You’ll get a full refund.',
            ],
            OrderStatus::Cancelled => [
                'title' => "Order {$number} is cancelled",
                'body' => 'You’ll get a full refund.',
            ],
            OrderStatus::PendingPayment => null,
        };
    }
}
