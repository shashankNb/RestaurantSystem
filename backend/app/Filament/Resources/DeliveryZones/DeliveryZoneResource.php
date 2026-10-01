<?php

namespace App\Filament\Resources\DeliveryZones;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\DeliveryZones\Pages\ManageDeliveryZones;
use App\Models\DeliveryZone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryZoneResource extends Resource
{
    protected static ?string $model = DeliveryZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Delivery zones';

    protected static ?string $modelLabel = 'delivery zone';

    protected static ?string $pluralModelLabel = 'Delivery zones';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText('For your reference, e.g. “Inner Melbourne”.')
                    ->required()
                    ->maxLength(100),
                Toggle::make('is_active')
                    ->label('Delivering here')
                    ->default(true)
                    ->inline(false),
                TagsInput::make('postcodes')
                    ->helperText('Type a postcode and press Enter.')
                    ->placeholder('3000')
                    ->required()
                    ->nestedRecursiveRules(['regex:/^\d{4}$/'])
                    ->columnSpanFull(),
                MoneyInput::make('fee_cents')
                    ->label('Delivery fee')
                    ->required(),
                MoneyInput::make('min_order_cents')
                    ->label('Minimum order')
                    ->helperText('Before the delivery fee.')
                    ->default(0)
                    ->required(),
                TextInput::make('estimated_minutes')
                    ->label('Delivery time')
                    ->helperText('From order to door, shown to customers.')
                    ->required()
                    ->integer()
                    ->minValue(5)
                    ->maxValue(240)
                    ->suffix('min'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('postcodes')
                    ->badge(),
                TextColumn::make('fee_cents')
                    ->label('Fee')
                    ->money('AUD', divideBy: 100),
                TextColumn::make('min_order_cents')
                    ->label('Minimum')
                    ->money('AUD', divideBy: 100),
                TextColumn::make('estimated_minutes')
                    ->label('Delivery time')
                    ->suffix(' min'),
                ToggleColumn::make('is_active')
                    ->label('Delivering'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No delivery zones')
            ->emptyStateDescription('Customers can only choose pickup until you add a zone.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeliveryZones::route('/'),
        ];
    }
}
