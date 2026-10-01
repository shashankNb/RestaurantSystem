<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FulfilmentType: string implements HasLabel
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::Delivery => 'Delivery',
        };
    }
}
