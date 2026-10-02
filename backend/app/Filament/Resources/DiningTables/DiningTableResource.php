<?php

namespace App\Filament\Resources\DiningTables;

use App\Filament\Resources\DiningTables\Pages\ManageDiningTables;
use App\Filament\Resources\DiningTables\Pages\PrintTableQrCodes;
use App\Models\DiningTable;
use App\Support\TableQrCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The restaurant's tables, for dine-in orders: customers choose one in the app, or scan its
 * QR code, and staff bring the order over.
 */
class DiningTableResource extends Resource
{
    protected static ?string $model = DiningTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Tables';

    protected static ?string $modelLabel = 'table';

    protected static ?string $pluralModelLabel = 'Tables';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Table')
                    ->helperText('As it’s marked on the table, e.g. 12 or A3. It’s part of the table’s QR code: print a new one if you change it.')
                    ->required()
                    ->maxLength(20)
                    ->regex('/^[\pL\pN][\pL\pN \-]*$/u')
                    ->validationMessages(['regex' => 'Use letters, numbers, spaces and hyphens, like 12 or Patio 2.'])
                    ->scopedUnique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('Taking orders')
                    ->helperText('Turn off to hide the table from customers, e.g. while it’s booked out.')
                    ->default(true)
                    ->inline(false),
                TextInput::make('sort_order')
                    ->label('Position')
                    ->helperText('Lower numbers come first in the list customers choose from.')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999)
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('sort_order')->orderByRaw('LENGTH(label)')->orderBy('label'))
            ->columns([
                TextColumn::make('label')
                    ->label('Table')
                    ->weight('bold')
                    ->searchable(),
                ToggleColumn::make('is_active')
                    ->label('Taking orders'),
                TextColumn::make('ordering_url')
                    ->label('QR code opens')
                    ->state(fn (DiningTable $record): string => $record->orderingUrl())
                    ->color('gray')
                    ->copyable(),
            ])
            ->recordActions([
                Action::make('qrCode')
                    ->label('QR code')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->modalHeading(fn (DiningTable $record): string => "Table {$record->label}")
                    ->modalDescription('Print it and put it on the table. Scanning it opens your menu with this table chosen.')
                    ->modalContent(fn (DiningTable $record) => view('filament.dining-tables.qr-code', [
                        'svg' => TableQrCode::svg($record),
                        'url' => $record->orderingUrl(),
                        'filename' => 'table-'.str($record->label)->slug().'.svg',
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No tables yet')
            ->emptyStateDescription('Add your tables so customers can order from them: they choose their table, or scan its QR code.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDiningTables::route('/'),
            'print' => PrintTableQrCodes::route('/print'),
        ];
    }
}
