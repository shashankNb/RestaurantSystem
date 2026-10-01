<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Order lifecycle. Which transitions are allowed lives in OrderService, the
 * single state machine; this enum only names and describes the states.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    case PendingPayment = 'pending_payment';
    case Placed = 'placed';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Placed => 'Placed',
            self::Accepted => 'Accepted',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::OutForDelivery => 'Out for delivery',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingPayment, self::Completed => 'gray',
            self::Placed => 'warning',
            self::Accepted, self::Preparing => 'info',
            self::Ready, self::OutForDelivery => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /**
     * No further transitions are possible from a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /**
     * Paid orders the kitchen still has to act on.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Placed, self::Accepted, self::Preparing, self::Ready, self::OutForDelivery];
    }
}
