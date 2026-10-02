<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Restaurant;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->formatStateUsing(fn (Order $record): string => "#{$record->display_number}")
                    ->placeholder('Unpaid')
                    ->weight('bold'),
                TextColumn::make('created_at')
                    ->label('Ordered')
                    ->dateTime('D j M, g:i a')
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->description(fn (Order $record): ?string => $record->customer_phone)
                    ->searchable(['customer_name', 'customer_email', 'customer_phone']),
                TextColumn::make('fulfilment_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Order $record): ?string => $record->table_label === null ? null : "Table {$record->table_label}"),
                TextColumn::make('scheduled_for')
                    ->label('Wanted for')
                    ->dateTime('D j M, g:i a')
                    ->placeholder('ASAP'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge(),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->money('AUD', divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class)
                    ->multiple(),
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(PaymentStatus::class),
                SelectFilter::make('fulfilment_type')
                    ->label('Type')
                    ->options(FulfilmentType::class),
                Filter::make('ordered_between')
                    ->schema([
                        DatePicker::make('from')->label('Ordered from'),
                        DatePicker::make('until')->label('Ordered until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::orderedBetween($query, $data['from'] ?? null, $data['until'] ?? null))
                    ->indicateUsing(function (array $data): array {
                        return array_values(array_filter([
                            empty($data['from']) ? null : 'From '.Carbon::parse($data['from'])->format('j M Y'),
                            empty($data['until']) ? null : 'Until '.Carbon::parse($data['until'])->format('j M Y'),
                        ]));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders appear here as soon as customers check out.');
    }

    /**
     * The dates are the restaurant's local calendar days; created_at is UTC.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function orderedBetween(Builder $query, ?string $from, ?string $until): Builder
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();

        return $query
            ->when($from, fn (Builder $query, string $from) => $query->where(
                'created_at', '>=', Carbon::parse($from, $restaurant->timezone)->startOfDay()->utc(),
            ))
            ->when($until, fn (Builder $query, string $until) => $query->where(
                'created_at', '<=', Carbon::parse($until, $restaurant->timezone)->endOfDay()->utc(),
            ));
    }
}
