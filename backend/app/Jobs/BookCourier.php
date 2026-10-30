<?php

namespace App\Jobs;

use App\Enums\CourierStatus;
use App\Models\Order;
use App\Services\CourierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Books a courier's driver for a delivery order the kitchen has accepted. Queued so DoorDash
 * never holds up the kitchen, and tried again while DoorDash can't be reached (a booking is
 * never made twice; see CourierService::book). If DoorDash never answers, the kitchen is told
 * and can book again or deliver it.
 */
class BookCourier implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public readonly int $orderId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CourierService $couriers): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order !== null) {
            $couriers->book($order);
        }
    }

    public function failed(Throwable $exception): void
    {
        $order = Order::query()->find($this->orderId);

        // Unless the kitchen booked one meanwhile.
        if ($order !== null && $order->courierCanBeBooked() && $order->courier_status !== CourierStatus::Failed) {
            app(CourierService::class)->bookingFailed($order, 'DoorDash didn’t answer, so no driver is booked. Book again, or deliver it yourselves.');
        }
    }
}
