<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\PushNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the customer's phone about a status change. Sent for the status the order had
 * when the job was queued, and skipped if the order has moved on since (a newer job
 * covers it).
 */
class SendOrderPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $orderId, public readonly OrderStatus $status) {}

    public function handle(PushNotifications $push): void
    {
        $order = Order::query()->with(['restaurant', 'user'])->find($this->orderId);

        if ($order === null || $order->status !== $this->status) {
            return;
        }

        $push->sendOrderUpdate($order);
    }
}
