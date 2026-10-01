<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PromoCodeType: string implements HasLabel
{
    /** `value` is a whole percentage of the subtotal (1–100). */
    case Percent = 'percent';

    /** `value` is an amount in cents. */
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => 'Percentage off',
            self::Fixed => 'Fixed amount off',
        };
    }
}
