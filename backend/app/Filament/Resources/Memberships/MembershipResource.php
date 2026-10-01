<?php

namespace App\Filament\Resources\Memberships;

use App\Enums\RestaurantRole;
use App\Filament\Resources\Memberships\Pages\ManageMemberships;
use App\Models\Membership;
use App\Models\Restaurant;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Staff accounts: who can use the kitchen screens (staff) and this back office
 * (owners). Each row is a membership; the user account itself is shared.
 */
class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static ?string $slug = 'staff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff account';

    protected static ?string $pluralModelLabel = 'Staff accounts';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Membership ? $record->user->name : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('create'),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('create')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        /** @var Restaurant $restaurant */
                        $restaurant = Filament::getTenant();

                        if ($restaurant->users()->where('email', $value)->exists()) {
                            $fail('This person already has access. Edit their role instead.');
                        }
                    }),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::defaults())
                    ->helperText('They sign in to the kitchen screens with this. If the email already has an account, their existing password stays.')
                    ->visibleOn('create'),
                Select::make('role')
                    ->options(RestaurantRole::class)
                    ->default(RestaurantRole::Staff)
                    ->required()
                    ->helperText('Staff use the kitchen screens. Owners can also use this back office.')
                    ->disabled(fn (?Membership $record): bool => $record !== null && self::isLastOwner($record)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('j M Y'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Change role'),
                DeleteAction::make()
                    ->label('Remove access')
                    ->modalDescription('They will no longer be able to sign in to this restaurant’s kitchen screens or back office. Their account itself isn’t deleted.')
                    ->disabled(fn (Membership $record): bool => $record->user_id === auth()->id() || self::isLastOwner($record))
                    ->tooltip(fn (Membership $record): ?string => match (true) {
                        $record->user_id === auth()->id() => 'You can’t remove your own access.',
                        self::isLastOwner($record) => 'A restaurant needs at least one owner.',
                        default => null,
                    }),
            ]);
    }

    public static function isLastOwner(Membership $membership): bool
    {
        return $membership->role === RestaurantRole::Owner
            && Membership::query()
                ->where('restaurant_id', $membership->restaurant_id)
                ->where('role', RestaurantRole::Owner)
                ->count() === 1;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberships::route('/'),
        ];
    }
}
