<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\InvalidOrderTransition;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Rejects and refunds paid orders the kitchen hasn't accepted in time (the restaurant's
 * auto_reject_minutes, counted from when it was open), and tells the customer. Runs every
 * minute.
 */
class AutoRejectOrders extends Command
{
    public const REASON = 'It wasn’t confirmed in time';

    protected $signature = 'orders:auto-reject';

    protected $description = 'Reject and refund orders not accepted within the restaurant’s auto-reject time';

    public function handle(OrderService $orders): int
    {
        $now = now();
        $rejected = 0;

        Order::query()
            ->where('status', OrderStatus::Placed)
            ->where('placed_at', '<=', $now->copy()->subMinute())
            ->with('restaurant')
            ->lazyById()
            ->each(function (Order $order) use ($orders, $now, &$rejected): void {
                if ($now->lessThan($orders->acceptDeadline($order))) {
                    return;
                }

                try {
                    $orders->reject($order, self::REASON, null);
                    $rejected++;
                } catch (InvalidOrderTransition) {
                    // Accepted since the query ran: leave it.
                }
            });

        $this->info("Rejected {$rejected} ".str('order')->plural($rejected).' not accepted in time.');

        return self::SUCCESS;
    }
}
