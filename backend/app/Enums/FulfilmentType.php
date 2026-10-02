<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FulfilmentType: string implements HasLabel
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    /** Eaten at one of the restaurant's tables: staff bring the order over. */
    case DineIn = 'dine_in';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::Delivery => 'Delivery',
            self::DineIn => 'Dine in',
        };
    }
}
