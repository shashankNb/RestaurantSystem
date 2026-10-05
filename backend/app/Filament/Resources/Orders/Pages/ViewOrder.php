<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentsUnavailable;
use App\Services\OrderService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkPayment')
                ->label('Check payment')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::PendingPayment)
                ->action(function (Order $record): void {
                    try {
                        $order = app(OrderService::class)->reconcilePayment($record);
                    } catch (PaymentsUnavailable) {
                        Notification::make()
                            ->title("{$record->payment_processor->getLabel()} couldn’t be asked")
                            ->body('Try again in a moment.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $order->status === OrderStatus::PendingPayment
                        ? Notification::make()
                            ->title('Not paid')
                            ->body("{$record->payment_processor->getLabel()} has no completed payment for this order. Unpaid orders are cancelled 30 minutes after they’re placed, or cancel it now.")
                            ->warning()
                            ->send()
                        : Notification::make()
                            ->title('Paid: it’s gone to the kitchen')
                            ->body('The payment had gone through; only the message saying so was lost.')
                            ->success()
                            ->send();

                    $this->refreshFormData(['status', 'payment_status']);
                }),
            Action::make('cancelUnpaid')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::PendingPayment)
                ->requiresConfirmation()
                ->modalHeading('Cancel this order?')
                ->modalDescription(fn (Order $record): string => "It’s still waiting for payment. {$record->payment_processor->getLabel()} is checked first: if the customer did pay, they’re refunded in full.")
                ->modalSubmitActionLabel('Cancel order')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->helperText('Saved on the order’s timeline. The customer sees it too.')
                        ->required()
                        ->maxLength(200)
                        ->rows(2),
                ])
                ->action(function (Order $record, array $data): void {
                    /** @var User $owner */
                    $owner = auth()->user();

                    try {
                        $order = app(OrderService::class)->cancelUnpaid($record, $owner, trim((string) $data['reason']));
                    } catch (PaymentsUnavailable) {
                        Notification::make()
                            ->title("{$record->payment_processor->getLabel()} couldn’t be asked, so nothing was cancelled")
                            ->body('Try again in a moment.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Order cancelled')
                        ->body($order->payment_status === PaymentStatus::Paid || $order->payment_status === PaymentStatus::Refunded
                            ? 'The customer had paid after all, so they’re being refunded in full.'
                            : 'It wasn’t paid, and now can’t be.')
                        ->success()
                        ->send();
                }),
            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (Order $record): bool => $record->payment_status === PaymentStatus::Paid)
                ->requiresConfirmation()
                ->modalHeading('Refund this order in full?')
                ->modalDescription(fn (Order $record): string => 'The customer gets '.Money::format($record->total_cents)
                    .' back on the card they paid with.'.($record->status->isFinal() ? '' : ' The order is cancelled too.'))
                ->modalSubmitActionLabel('Refund')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->helperText('Saved on the order’s timeline. For an order that isn’t finished, the customer sees it too.')
                        ->required()
                        ->maxLength(200)
                        ->rows(2),
                ])
                ->action(function (Order $record, array $data): void {
                    /** @var User $owner */
                    $owner = auth()->user();

                    app(OrderService::class)->refund($record, $owner, trim((string) $data['reason']));

                    Notification::make()
                        ->title("Refund sent to {$record->payment_processor->getLabel()}")
                        ->body('It usually shows on the customer’s statement within 5 to 10 business days.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
