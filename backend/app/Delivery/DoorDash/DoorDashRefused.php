<?php

namespace App\Delivery\DoorDash;

use RuntimeException;

/**
 * DoorDash understood the request and said no: an address it can't deliver to, a delivery it
 * won't take, an ID it already has (409), one it doesn't know (404), or a delivery it can't
 * cancel any more (409). The message is DoorDash's own, for staff and logs; customers get
 * one of ours.
 */
final class DoorDashRefused extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $reason,
        /** The request field DoorDash objected to, when it says. */
        public readonly ?string $field = null,
    ) {
        parent::__construct($reason);
    }

    /** DoorDash doesn't know the delivery ID. */
    public function isUnknown(): bool
    {
        return $this->status === 404;
    }
}
