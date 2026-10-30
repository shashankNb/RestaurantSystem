<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What customers pay for a delivery DoorDash makes, as the owner chooses in the back office:
 * the delivery zone's own fee (DoorDash's charge is then the restaurant's cost), or DoorDash's
 * price for the customer's address.
 */
enum DoorDashFee: string implements HasLabel
{
    case Zone = 'zone';
    case Quote = 'quote';

    public function getLabel(): string
    {
        return match ($this) {
            self::Zone => 'Your delivery zone’s fee',
            self::Quote => 'DoorDash’s price for their address',
        };
    }
}
