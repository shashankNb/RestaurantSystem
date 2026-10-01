<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;

/**
 * A dollars-and-cents input for a column that stores integer cents.
 *
 * Deliberately not ->numeric(): that casts the state to a float, which would
 * show $17.90 as "17.9". Validation rules keep it numeric instead.
 */
final class MoneyInput
{
    public static function make(string $name, bool $allowNegative = false): TextInput
    {
        return TextInput::make($name)
            ->prefix('$')
            ->inputMode('decimal')
            ->rule('numeric')
            ->regex($allowNegative ? '/^-?\d+(\.\d{1,2})?$/' : '/^\d+(\.\d{1,2})?$/')
            ->validationMessages(['regex' => 'Enter an amount in dollars, like 12.50.'])
            ->minValue($allowNegative ? null : 0)
            ->maxValue(99_999)
            ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state)
                ? number_format(((int) $state) / 100, 2, '.', '')
                : null)
            ->dehydrateStateUsing(fn (mixed $state): ?int => is_numeric($state)
                ? (int) round(((float) $state) * 100)
                : null);
    }
}
