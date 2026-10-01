<?php

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Enums\Allergen;
use App\Enums\DietaryTag;
use App\Filament\Forms\MoneyInput;
use App\Models\Restaurant;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class MenuItemForm
{
    /**
     * Every submitted id must belong to the current restaurant. The select only
     * offers the restaurant's own rows, but a crafted request could send others.
     */
    private static function ownedByRestaurant(string $relationship, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($relationship, $message): void {
            $ids = array_values(array_unique(array_filter(Arr::wrap($value))));

            if ($ids === []) {
                return;
            }

            /** @var Restaurant $restaurant */
            $restaurant = Filament::getTenant();

            if (count($ids) !== $restaurant->{$relationship}()->whereKey($ids)->count()) {
                $fail($message);
            }
        };
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Item')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->preload()
                            ->searchable()
                            ->required()
                            ->rule(fn (): Closure => self::ownedByRestaurant('menuCategories', 'Choose one of your categories.')),
                        MoneyInput::make('price_cents')
                            ->label('Price')
                            ->helperText('Including GST.')
                            ->minValue(0.01)
                            ->required(),
                        Select::make('modifierGroups')
                            ->label('Options customers choose')
                            ->helperText('For example “Choose filling” or “Spice level”.')
                            ->relationship('modifierGroups', 'name')
                            ->multiple()
                            ->preload()
                            ->rule(fn (): Closure => self::ownedByRestaurant('modifierGroups', 'Choose option groups from your own menu.')),
                        Textarea::make('description')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Photo')
                    ->columnSpan(1)
                    ->schema([
                        FileUpload::make('image')
                            ->hiddenLabel()
                            ->helperText('A landscape photo works best. JPEG, PNG or WebP up to 4 MB.')
                            ->image()
                            ->imageEditor()
                            ->disk(config('ordering.media_disk'))
                            ->directory('menu-items')
                            ->visibility('public')
                            ->maxSize(4096),
                    ]),

                Section::make('Dietary and allergens')
                    ->columnSpan(2)
                    ->schema([
                        CheckboxList::make('dietary_tags')
                            ->label('Dietary')
                            ->options(DietaryTag::class)
                            ->columns(3),
                        CheckboxList::make('allergens')
                            ->label('Contains')
                            ->options(Allergen::class)
                            ->columns(4),
                    ]),

                Section::make('Availability')
                    ->columnSpan(1)
                    ->schema([
                        Toggle::make('is_available')
                            ->label('In stock')
                            ->helperText('Turn off when it sells out. Kitchen staff can switch this too.')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Show on menu')
                            ->default(true),
                    ]),
            ]);
    }
}
