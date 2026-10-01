<?php

namespace App\Services;

use App\Enums\OrderStatus;
use DomainException;

/**
 * A status change the order lifecycle doesn't allow. The API answers 422 with the message.
 */
final class InvalidOrderTransition extends DomainException
{
    public function __construct(public readonly OrderStatus $from, public readonly OrderStatus $to, string $message)
    {
        parent::__construct($message);
    }

    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        $was = mb_strtolower($from->getLabel());
        $message = $from->isFinal()
            ? "This order is already {$was}, so it can’t change any more."
            : "An order that’s {$was} can’t be marked ".mb_strtolower($to->getLabel()).'.';

        return new self($from, $to, $message);
    }
}
