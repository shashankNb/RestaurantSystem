<?php

namespace App\Enums;

/**
 * Square's two environments. The sandbox takes test payments with Square's test cards;
 * production takes real ones. A restaurant connects its Square account in one of them.
 */
enum SquareEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /** Square's API and sign-in host. */
    public function host(): string
    {
        return match ($this) {
            self::Sandbox => 'https://connect.squareupsandbox.com',
            self::Production => 'https://connect.squareup.com',
        };
    }

    /** As the back office says it: "sandbox (test)" or "live". */
    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'sandbox (test)',
            self::Production => 'live',
        };
    }
}
