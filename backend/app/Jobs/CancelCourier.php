<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\CourierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls off a cancelled order's courier driver. Queued like refunds, and tried again while
 * DoorDash can't be reached.
 */
class CancelCourier implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $orderId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(CourierService $couriers): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order !== null) {
            $couriers->cancel($order);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('A cancelled order’s DoorDash driver couldn’t be called off; cancel it in DoorDash’s portal.', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }
}
