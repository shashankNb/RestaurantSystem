<?php

namespace App\Filament\Resources\ModifierGroups\Tables;

use App\Models\ModifierGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('rule')
                    ->state(fn (ModifierGroup $record): string => $record->selectionRule()),
                TextColumn::make('options_count')
                    ->label('Options')
                    ->counts('options'),
                TextColumn::make('menu_items_count')
                    ->label('Used by')
                    ->counts('menuItems')
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '1 item' : "{$state} items"),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('No option groups yet')
            ->emptyStateDescription('Create a group such as “Spice level”, then attach it to menu items.');
    }
}
