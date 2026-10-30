<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a courier's delivery of an order is up to, as DoorDash reports it (by webhook, or when
 * asked). The order's own status follows it twice: out for delivery once the driver has the
 * food, and completed once it's delivered.
 */
enum CourierStatus: string implements HasColor, HasLabel
{
    /** DoorDash took the booking and is finding a driver. */
    case Booked = 'booked';
    case DriverAssigned = 'driver_assigned';
    case AtPickup = 'at_pickup';
    case PickedUp = 'picked_up';
    case AtDropoff = 'at_dropoff';
    case Delivered = 'delivered';
    /** Called off: by DoorDash or its driver, or because the restaurant cancelled the order. */
    case Cancelled = 'cancelled';
    /** The driver couldn't deliver it and is bringing it back. */
    case Returning = 'returning';
    case Returned = 'returned';
    /** DoorDash refused the booking, or never answered. */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Booked => 'Finding a driver',
            self::DriverAssigned => 'Driver on the way to the restaurant',
            self::AtPickup => 'Driver at the restaurant',
            self::PickedUp => 'On its way to the customer',
            self::AtDropoff => 'Driver at the customer’s address',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Delivery cancelled',
            self::Returning => 'Coming back to the restaurant',
            self::Returned => 'Brought back to the restaurant',
            self::Failed => 'No driver booked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Booked => 'gray',
            self::DriverAssigned, self::AtPickup => 'info',
            self::PickedUp, self::AtDropoff, self::Delivered => 'success',
            self::Cancelled, self::Returning, self::Returned, self::Failed => 'danger',
        };
    }

    /** Nothing more happens to this booking; another can be made. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned, self::Failed], true);
    }

    /**
     * Bookings DoorDash is still working on.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Booked, self::DriverAssigned, self::AtPickup, self::PickedUp, self::AtDropoff, self::Returning];
    }

    /**
     * How far along the delivery is. News never moves a delivery back a stage, so an update
     * that arrives late can't undo a newer one. Within the first stage it can go back:
     * DoorDash finds another driver when one drops out.
     */
    public function stage(): int
    {
        return match ($this) {
            self::Booked, self::DriverAssigned, self::AtPickup => 1,
            self::PickedUp, self::AtDropoff, self::Returning => 2,
            self::Delivered, self::Cancelled, self::Returned, self::Failed => 3,
        };
    }

    /** From DoorDash's delivery_status, as Get Delivery returns it; null for a quote. */
    public static function fromDoorDashStatus(string $status): ?self
    {
        return match ($status) {
            'created' => self::Booked,
            'confirmed', 'enroute_to_pickup' => self::DriverAssigned,
            'arrived_at_pickup' => self::AtPickup,
            'picked_up', 'enroute_to_dropoff' => self::PickedUp,
            'arrived_at_dropoff' => self::AtDropoff,
            'delivered' => self::Delivered,
            'cancelled' => self::Cancelled,
            'enroute_to_return' => self::Returning,
            'returned' => self::Returned,
            default => null,
        };
    }

    /** From a DoorDash webhook's event_name; null for events the platform doesn't act on. */
    public static function fromDoorDashEvent(string $event): ?self
    {
        return match ($event) {
            'DASHER_CONFIRMED' => self::DriverAssigned,
            'DASHER_CONFIRMED_PICKUP_ARRIVAL' => self::AtPickup,
            'DASHER_PICKED_UP' => self::PickedUp,
            'DASHER_CONFIRMED_DROPOFF_ARRIVAL' => self::AtDropoff,
            'DASHER_DROPPED_OFF' => self::Delivered,
            'DELIVERY_CANCELLED' => self::Cancelled,
            'DELIVERY_RETURN_INITIALIZED', 'DASHER_CONFIRMED_RETURN_ARRIVAL' => self::Returning,
            'DELIVERY_RETURNED' => self::Returned,
            default => null,
        };
    }
}
