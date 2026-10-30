<?php

namespace App\Console\Commands;

use App\Delivery\CourierUnavailable;
use App\Enums\Courier;
use App\Enums\CourierStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CourierService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asks DoorDash where each delivery it's still making is up to, for any news its webhook
 * didn't bring: a webhook lost, or one DoorDash can't send at all (to a computer in
 * development, say). Also gives up on bookings that never got an answer, so the kitchen can
 * book again. Runs every minute.
 */
class SyncCourierDeliveries extends Command
{
    protected $signature = 'orders:sync-couriers';

    protected $description = 'Bring delivery orders up to date with where DoorDash’s drivers are';

    public function handle(CourierService $couriers): int
    {
        $checked = 0;

        Order::query()
            ->where('courier', Courier::DoorDash)
            ->whereNotNull('courier_delivery_id')
            ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery])
            ->where(fn (Builder $query) => $query->whereNull('courier_status')->orWhereIn('courier_status', CourierStatus::active()))
            ->with('restaurant')
            ->lazyById()
            ->each(function (Order $order) use ($couriers, &$checked): void {
                try {
                    $couriers->refresh($order);
                    $checked++;
                } catch (CourierUnavailable $exception) {
                    // The next run asks again.
                    report($exception);
                }
            });

        $this->info("Checked {$checked} DoorDash ".str('delivery')->plural($checked).'.');

        return self::SUCCESS;
    }
}
