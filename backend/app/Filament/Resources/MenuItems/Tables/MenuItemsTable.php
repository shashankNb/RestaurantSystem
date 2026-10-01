<?php

namespace App\Filament\Resources\MenuItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->disk(config('ordering.media_disk'))
                    ->square()
                    ->imageSize(48),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->money('AUD', divideBy: 100)
                    ->sortable(),
                ToggleColumn::make('is_available')
                    ->label('In stock'),
                ToggleColumn::make('is_active')
                    ->label('On menu'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->preload(),
                TernaryFilter::make('is_available')
                    ->label('In stock'),
                TernaryFilter::make('is_active')
                    ->label('On menu'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No menu items yet')
            ->emptyStateDescription('Add your first item. You’ll need a category for it first.');
    }
}
