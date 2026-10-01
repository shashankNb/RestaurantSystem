<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\InvalidOrderTransition;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Cancels checkouts that were never paid, and their PaymentIntents. Runs every minute.
 */
class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Cancel orders still waiting for payment after config(ordering.pending_payment_minutes) minutes';

    public function handle(OrderService $orders): int
    {
        $cutoff = now()->subMinutes((int) config('ordering.pending_payment_minutes', 30));
        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->where('created_at', '<=', $cutoff)
            ->lazyById()
            ->each(function (Order $order) use ($orders, &$cancelled): void {
                try {
                    $orders->expireUnpaid($order);
                    $cancelled++;
                } catch (InvalidOrderTransition) {
                    // Paid (or cancelled) since the query ran: leave it.
                }
            });

        $this->info("Cancelled {$cancelled} unpaid ".str('order')->plural($cancelled).'.');

        return self::SUCCESS;
    }
}
