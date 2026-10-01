<?php

namespace App\Filament\Resources\OpeningHours;

use App\Filament\Resources\OpeningHours\Pages\ManageOpeningHours;
use App\Models\OpeningHour;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Regular weekly hours. Times are local wall-clock times, so the pickers are
 * pinned to the app timezone to stop Filament converting them.
 */
class OpeningHourResource extends Resource
{
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        0 => 'Sunday',
    ];

    protected static ?string $model = OpeningHour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Opening hours';

    protected static ?string $modelLabel = 'opening hours';

    protected static ?string $pluralModelLabel = 'Opening hours';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('day_of_week')
                    ->label('Day')
                    ->options(self::DAYS)
                    ->required()
                    ->columnSpanFull(),
                TimePicker::make('opens_at')
                    ->label('Opens')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->required(),
                TimePicker::make('closes_at')
                    ->label('Closes')
                    ->helperText('Earlier than opening means it closes after midnight.')
                    ->timezone(config('app.timezone'))
                    ->seconds(false)
                    ->required()
                    ->different('opens_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Monday first, then each day's shifts in time order.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw('(day_of_week + 6) % 7')
                ->orderBy('opens_at'))
            ->paginated(false)
            ->columns([
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (int $state): string => self::DAYS[$state]),
                TextColumn::make('opens_at')
                    ->label('Hours')
                    ->formatStateUsing(fn (OpeningHour $record): string => self::formatShift($record->opens_at, $record->closes_at)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No opening hours')
            ->emptyStateDescription('Customers can only schedule orders once you add your hours.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOpeningHours::route('/'),
        ];
    }

    public static function formatShift(string $opensAt, string $closesAt): string
    {
        $format = fn (string $time): string => Carbon::createFromFormat('H:i:s', strlen($time) === 5 ? "{$time}:00" : $time)->format('g:i a');
        $nextDay = $closesAt <= $opensAt ? ' (next day)' : '';

        return "{$format($opensAt)} – {$format($closesAt)}{$nextDay}";
    }
}
