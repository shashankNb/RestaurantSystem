<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\FulfilmentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusEvent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('display_number')
                            ->label('Number')
                            ->prefix('#')
                            ->placeholder('Not paid yet'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('payment_status')
                            ->label('Payment')
                            ->badge(),
                        TextEntry::make('fulfilment_type')
                            ->label('Type')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('scheduled_for')
                            ->label('Wanted for')
                            ->dateTime('D j M, g:i a')
                            ->placeholder('As soon as possible'),
                        TextEntry::make('estimated_ready_at')
                            ->label('Estimated ready')
                            ->dateTime('g:i a')
                            ->placeholder('Not accepted yet'),
                        TextEntry::make('created_at')
                            ->label('Ordered')
                            ->dateTime('D j M Y, g:i a'),
                        TextEntry::make('prep_minutes')
                            ->label('Prep time')
                            ->suffix(' min')
                            ->placeholder('—'),
                        TextEntry::make('promo_code')
                            ->label('Promo code')
                            ->placeholder('None'),
                        TextEntry::make('rejection_reason')
                            ->label('Reason for rejecting')
                            ->visible(fn (Order $record): bool => $record->rejection_reason !== null)
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Customer notes')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),

                Section::make('Customer')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Name'),
                        TextEntry::make('customer_phone')
                            ->label('Phone')
                            ->placeholder('—'),
                        TextEntry::make('customer_email')
                            ->label('Email')
                            ->placeholder('—'),
                        TextEntry::make('delivery_line1')
                            ->label('Deliver to')
                            ->state(fn (Order $record): string => implode(', ', array_filter([
                                $record->delivery_line1,
                                $record->delivery_line2,
                                trim("{$record->delivery_suburb} {$record->delivery_state} {$record->delivery_postcode}"),
                            ])))
                            ->visible(fn (Order $record): bool => $record->fulfilment_type === FulfilmentType::Delivery),
                        TextEntry::make('delivery_instructions')
                            ->label('Delivery instructions')
                            ->placeholder('None')
                            ->visible(fn (Order $record): bool => $record->fulfilment_type === FulfilmentType::Delivery),
                    ]),

                Section::make('Items')
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('name')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn (OrderItem $record): string => "{$record->quantity} × {$record->name}")
                                    ->weight('bold')
                                    ->columnSpan(2),
                                TextEntry::make('modifiers')
                                    ->hiddenLabel()
                                    ->state(fn (OrderItem $record): string => $record->modifiers
                                        ->map(fn (OrderItemModifier $modifier): string => "{$modifier->group_name}: {$modifier->name}")
                                        ->implode("\n"))
                                    ->placeholder('No options')
                                    ->color('gray'),
                                TextEntry::make('line_total_cents')
                                    ->hiddenLabel()
                                    ->money('AUD', divideBy: 100)
                                    ->alignEnd(),
                                TextEntry::make('notes')
                                    ->hiddenLabel()
                                    ->prefix('Note: ')
                                    ->visible(fn (OrderItem $record): bool => filled($record->notes))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Totals')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('subtotal_cents')
                            ->label('Subtotal')
                            ->money('AUD', divideBy: 100)
                            ->inlineLabel(),
                        TextEntry::make('delivery_fee_cents')
                            ->label('Delivery fee')
                            ->money('AUD', divideBy: 100)
                            ->inlineLabel(),
                        TextEntry::make('discount_cents')
                            ->label('Discount')
                            ->money('AUD', divideBy: 100)
                            ->prefix('−')
                            ->inlineLabel(),
                        TextEntry::make('total_cents')
                            ->label('Total')
                            ->money('AUD', divideBy: 100)
                            ->weight('bold')
                            ->inlineLabel(),
                        TextEntry::make('gst_cents')
                            ->label('Includes GST')
                            ->money('AUD', divideBy: 100)
                            ->inlineLabel(),
                    ]),

                Section::make('Timeline')
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('statusEvents')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('to_status')
                                    ->hiddenLabel()
                                    ->badge(),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->dateTime('D j M, g:i:s a'),
                                TextEntry::make('note')
                                    ->hiddenLabel()
                                    ->state(fn (OrderStatusEvent $record): string => trim(implode(' ', array_filter([
                                        $record->user !== null ? "By {$record->user->name}." : null,
                                        $record->note,
                                    ]))))
                                    ->placeholder('—'),
                            ]),
                    ]),

                Section::make('Payment')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('stripe_payment_intent_id')
                            ->label('Stripe payment')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('stripe_refund_id')
                            ->label('Stripe refund')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('refunded_at')
                            ->label('Refunded')
                            ->dateTime('D j M, g:i a')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
