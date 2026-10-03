<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
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
