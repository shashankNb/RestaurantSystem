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
    /**
     * @param  array<string, string>  $fieldErrors  DoorDash's objection to each field it named
     */
    public function __construct(
        public readonly int $status,
        string $reason,
        /** The request field DoorDash objected to first, when it says. */
        public readonly ?string $field = null,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($reason);
    }

    /** DoorDash doesn't know the delivery ID. */
    public function isUnknown(): bool
    {
        return $this->status === 404;
    }

    /**
     * The refusal is about where the delivery goes: every field DoorDash objected to is part
     * of the drop-off, or, naming none, it's a logical refusal (422) such as an address outside
     * its coverage. Anything else is about the restaurant's details or the request itself, which
     * the customer can't fix.
     */
    public function isAboutDropoff(): bool
    {
        if ($this->fieldErrors === []) {
            return $this->status === 422;
        }

        foreach (array_keys($this->fieldErrors) as $field) {
            if (! str_starts_with($field, 'dropoff')) {
                return false;
            }
        }

        return true;
    }
}
