<?php

namespace App\Filament\Resources\MenuCategories;

use App\Filament\Resources\MenuCategories\Pages\ManageMenuCategories;
use App\Models\MenuCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class MenuCategoryResource extends Resource
{
    protected static ?string $model = MenuCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'Categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                Toggle::make('is_active')
                    ->label('Show on menu')
                    ->default(true)
                    ->inline(false),
                Textarea::make('description')
                    ->maxLength(500)
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),
                ToggleColumn::make('is_active')
                    ->label('On menu'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (MenuCategory $record): bool => $record->items()->exists())
                    ->tooltip(fn (MenuCategory $record): ?string => $record->items()->exists()
                        ? 'Move or delete this category’s items first.'
                        : null),
            ])
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('Create a category, such as “Momos”, then add items to it.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMenuCategories::route('/'),
        ];
    }
}
