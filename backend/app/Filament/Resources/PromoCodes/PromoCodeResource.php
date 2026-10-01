<?php

namespace App\Filament\Resources\PromoCodes;

use App\Enums\PromoCodeType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\PromoCodes\Pages\ManagePromoCodes;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Promo codes';

    protected static ?string $modelLabel = 'promo code';

    protected static ?string $pluralModelLabel = 'Promo codes';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->helperText('Letters, numbers, dashes and underscores. Customers can type it in any case.')
                    ->required()
                    ->maxLength(40)
                    ->alphaDash()
                    ->scopedUnique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
                Select::make('type')
                    ->label('Discount type')
                    ->options(PromoCodeType::class)
                    ->default(PromoCodeType::Percent)
                    ->required()
                    ->live(),
                TextInput::make('value')
                    ->label('Discount')
                    ->required()
                    ->inputMode('decimal')
                    ->prefix(fn (Get $get): ?string => self::isFixed($get('type')) ? '$' : null)
                    ->suffix(fn (Get $get): ?string => self::isFixed($get('type')) ? null : '% off')
                    ->rules(fn (Get $get): array => self::isFixed($get('type'))
                        ? ['numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01', 'max:1000']
                        : ['integer', 'min:1', 'max:100'])
                    ->formatStateUsing(fn (mixed $state, ?PromoCode $record): mixed => $record?->type === PromoCodeType::Fixed && is_numeric($state)
                        ? number_format(((int) $state) / 100, 2, '.', '')
                        : $state)
                    ->dehydrateStateUsing(fn (mixed $state, Get $get): int => self::isFixed($get('type'))
                        ? (int) round(((float) $state) * 100)
                        : (int) $state),
                MoneyInput::make('min_order_cents')
                    ->label('Minimum order')
                    ->helperText('Subtotal before delivery. 0 for none.')
                    ->default(0)
                    ->required(),
                TextInput::make('max_uses')
                    ->label('Usage limit')
                    ->helperText('Total paid orders that can use it. Leave empty for no limit.')
                    ->integer()
                    ->minValue(1),
                DateTimePicker::make('starts_at')
                    ->label('Starts')
                    ->seconds(false),
                DateTimePicker::make('ends_at')
                    ->label('Ends')
                    ->seconds(false)
                    ->after('starts_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('value')
                    ->label('Discount')
                    ->formatStateUsing(fn (PromoCode $record): string => $record->type === PromoCodeType::Fixed
                        ? '$'.number_format($record->value / 100, 2).' off'
                        : "{$record->value}% off"),
                TextColumn::make('min_order_cents')
                    ->label('Minimum')
                    ->money('AUD', divideBy: 100),
                TextColumn::make('uses_count')
                    ->label('Used')
                    ->formatStateUsing(fn (PromoCode $record): string => $record->max_uses === null
                        ? (string) $record->uses_count
                        : "{$record->uses_count} of {$record->max_uses}"),
                TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('j M Y, g:i a')
                    ->placeholder('No end date'),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No promo codes')
            ->emptyStateDescription('Create a code to offer a percentage or dollar discount.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePromoCodes::route('/'),
        ];
    }

    /**
     * The type select may hold the enum or its raw value, depending on where the state came from.
     */
    private static function isFixed(mixed $type): bool
    {
        return $type === PromoCodeType::Fixed || $type === PromoCodeType::Fixed->value;
    }
}
