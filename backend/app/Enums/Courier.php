<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who delivers a delivery order when it isn't the restaurant's own drivers. Each order keeps
 * the courier it was placed with, so its booking, updates and cancellation follow the order
 * even after the restaurant changes its settings.
 */
enum Courier: string implements HasLabel
{
    case DoorDash = 'doordash';

    public function getLabel(): string
    {
        return match ($this) {
            self::DoorDash => 'DoorDash',
        };
    }
}
