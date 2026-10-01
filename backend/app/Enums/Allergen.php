<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The allergens Food Standards Australia New Zealand requires businesses to declare.
 */
enum Allergen: string implements HasLabel
{
    case Gluten = 'gluten';
    case Wheat = 'wheat';
    case Egg = 'egg';
    case Milk = 'milk';
    case Peanut = 'peanut';
    case TreeNuts = 'tree_nuts';
    case Sesame = 'sesame';
    case Soy = 'soy';
    case Fish = 'fish';
    case Crustacea = 'crustacea';
    case Mollusc = 'mollusc';
    case Lupin = 'lupin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gluten => 'Gluten',
            self::Wheat => 'Wheat',
            self::Egg => 'Egg',
            self::Milk => 'Milk',
            self::Peanut => 'Peanut',
            self::TreeNuts => 'Tree nuts',
            self::Sesame => 'Sesame',
            self::Soy => 'Soy',
            self::Fish => 'Fish',
            self::Crustacea => 'Crustacea',
            self::Mollusc => 'Mollusc',
            self::Lupin => 'Lupin',
        };
    }
}
