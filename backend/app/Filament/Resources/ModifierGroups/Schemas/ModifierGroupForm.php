<?php

namespace App\Filament\Resources\ModifierGroups\Schemas;

use App\Filament\Forms\MoneyInput;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ModifierGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group')
                    ->description('Customers see the rule next to the group name, e.g. “Required · choose 1”.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->helperText('Shown to customers, e.g. “Choose filling”.')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('min_select')
                            ->label('Minimum choices')
                            ->helperText('0 makes the group optional.')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(20)
                            ->default(0)
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ((int) $value > count($get('options') ?? [])) {
                                    $fail('The minimum can’t be more than the number of options.');
                                }
                            }),
                        TextInput::make('max_select')
                            ->label('Maximum choices')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(20)
                            ->default(1)
                            ->gte('min_select'),
                    ]),

                Section::make('Options')
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add option')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(100),
                                MoneyInput::make('price_delta_cents', allowNegative: true)
                                    ->label('Price change')
                                    ->helperText('0 for no charge.')
                                    ->default(0)
                                    ->required(),
                                Toggle::make('is_available')
                                    ->label('In stock')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }
}
