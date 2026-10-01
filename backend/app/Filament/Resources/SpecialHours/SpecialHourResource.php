<?php

namespace App\Filament\Resources\SpecialHours;

use App\Filament\Resources\OpeningHours\OpeningHourResource;
use App\Filament\Resources\SpecialHours\Pages\ManageSpecialHours;
use App\Models\SpecialHour;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SpecialHourResource extends Resource
{
    protected static ?string $model = SpecialHour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Holidays & special hours';

    protected static ?string $modelLabel = 'special hours';

    protected static ?string $pluralModelLabel = 'Special hours';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->helperText('These hours replace your regular hours for this date.')
                    ->required()
                    ->scopedUnique(ignoreRecord: true),
                TextInput::make('note')
                    ->helperText('For example “Christmas Day”.')
                    ->maxLength(255),
                Toggle::make('is_closed')
                    ->label('Closed all day')
                    ->default(true)
                    ->live()
                    ->columnSpanFull(),
                TimePicker::make('opens_at')
                    ->label('Opens')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->hidden(fn (Get $get): bool => (bool) $get('is_closed'))
                    ->required(fn (Get $get): bool => ! $get('is_closed')),
                TimePicker::make('closes_at')
                    ->label('Closes')
                    ->helperText('Earlier than opening means it closes after midnight.')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->hidden(fn (Get $get): bool => (bool) $get('is_closed'))
                    ->required(fn (Get $get): bool => ! $get('is_closed'))
                    ->different('opens_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')
                    ->date('D j M Y'),
                TextColumn::make('is_closed')
                    ->label('Hours')
                    ->formatStateUsing(fn (SpecialHour $record): string => $record->is_closed || $record->opens_at === null || $record->closes_at === null
                        ? 'Closed'
                        : OpeningHourResource::formatShift($record->opens_at, $record->closes_at)),
                TextColumn::make('note')
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->query(fn (Builder $query): Builder => $query->where('date', '>=', now()->subDay()->toDateString()))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => self::normaliseHours($data)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No special hours')
            ->emptyStateDescription('Add public holidays and one-off changes so customers can’t order when you’re closed.');
    }

    /**
     * Opening times only apply when the restaurant is open that day.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseHours(array $data): array
    {
        if (! empty($data['is_closed'])) {
            $data['opens_at'] = null;
            $data['closes_at'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSpecialHours::route('/'),
        ];
    }
}
