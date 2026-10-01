<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RestaurantRole: string implements HasLabel
{
    /** Runs the back office and the kitchen screens. */
    case Owner = 'owner';

    /** Kitchen screens only. */
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Staff => 'Staff',
        };
    }
}
