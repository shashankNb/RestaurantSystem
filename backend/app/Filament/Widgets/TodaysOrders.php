<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class TodaysOrders extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();

        // "Today" is the restaurant's local day.
        $startOfToday = now($restaurant->timezone)->startOfDay()->utc();
        $placedToday = $restaurant->orders()->where('placed_at', '>=', $startOfToday);

        $orders = (clone $placedToday)->count();
        $salesCents = (int) (clone $placedToday)->where('payment_status', PaymentStatus::Paid)->sum('total_cents');
        $waiting = $restaurant->orders()->where('status', OrderStatus::Placed)->count();

        return [
            Stat::make('Orders today', (string) $orders),
            Stat::make('Sales today', Number::currency($salesCents / 100, $restaurant->currency, 'en_AU'))
                ->description('Paid orders, refunds excluded'),
            Stat::make('Waiting to be accepted', (string) $waiting)
                ->color($waiting > 0 ? 'warning' : 'gray'),
        ];
    }
}
