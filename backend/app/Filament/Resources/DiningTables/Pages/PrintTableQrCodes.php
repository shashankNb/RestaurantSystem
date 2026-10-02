<?php

namespace App\Filament\Resources\DiningTables\Pages;

use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Models\DiningTable;
use App\Models\Restaurant;
use App\Support\TableQrCode;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * A printable sheet with every table's QR code, to cut out and put on the tables.
 */
class PrintTableQrCodes extends Page
{
    protected static string $resource = DiningTableResource::class;

    protected string $view = 'filament.dining-tables.print';

    protected static ?string $title = 'Table QR codes';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->alpineClickHandler('window.print()'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = Filament::getTenant();

        return [
            'restaurant' => $restaurant->name,
            'dineInEnabled' => $restaurant->dine_in_enabled,
            'codes' => $restaurant->activeDiningTables->map(fn (DiningTable $table): array => [
                'label' => $table->label,
                'svg' => TableQrCode::svg($table),
            ])->all(),
        ];
    }
}
