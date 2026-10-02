<?php

namespace App\Filament\Resources\DiningTables\Pages;

use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Models\Restaurant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageDiningTables extends ManageRecords
{
    protected static string $resource = DiningTableResource::class;

    /** At most this many tables in one go. */
    private const MOST_AT_ONCE = 200;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print QR codes')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(DiningTableResource::getUrl('print')),
            Action::make('addSeveral')
                ->label('Add several')
                ->icon(Heroicon::OutlinedSquare2Stack)
                ->color('gray')
                ->modalHeading('Add numbered tables')
                ->modalDescription('Adds tables numbered from the first to the last. Numbers you already have are skipped.')
                ->schema([
                    TextInput::make('from')->label('First table')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('to')->label('Last table')->integer()->minValue(1)->required()->gte('from'),
                ])
                ->action(function (array $data): void {
                    $from = (int) $data['from'];
                    $to = min((int) $data['to'], $from + self::MOST_AT_ONCE - 1);
                    $added = $this->addTables($from, $to);

                    Notification::make()
                        ->title($added === 0 ? 'You already have those tables' : ($added === 1 ? 'Added 1 table' : "Added {$added} tables"))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    private function addTables(int $from, int $to): int
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();
        $existing = $restaurant->diningTables()->pluck('label')->map(fn (string $label): string => mb_strtolower($label))->all();
        $added = 0;

        foreach (range($from, $to) as $number) {
            if (! in_array((string) $number, $existing, true)) {
                $restaurant->diningTables()->create(['label' => (string) $number, 'is_active' => true, 'sort_order' => 0]);
                $added++;
            }
        }

        return $added;
    }
}
