<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DietaryTag: string implements HasLabel
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case GlutenFree = 'gluten_free';
    case DairyFree = 'dairy_free';
    case Halal = 'halal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vegetarian => 'Vegetarian',
            self::Vegan => 'Vegan',
            self::GlutenFree => 'Gluten free',
            self::DairyFree => 'Dairy free',
            self::Halal => 'Halal',
        };
    }
}
