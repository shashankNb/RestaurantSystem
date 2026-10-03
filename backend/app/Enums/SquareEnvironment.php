<?php

namespace App\Enums;

/**
 * Square's two environments. The sandbox takes test payments with Square's test cards;
 * production takes real ones. A restaurant's Square application ID says which it's in.
 */
enum SquareEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /** Square's API host. */
    public function host(): string
    {
        return match ($this) {
            self::Sandbox => 'https://connect.squareupsandbox.com',
            self::Production => 'https://connect.squareup.com',
        };
    }

    /** From an application ID: sandbox-sq0idb-… for the sandbox, sq0idp-… for production. */
    public static function fromApplicationId(?string $applicationId): ?self
    {
        return match (true) {
            $applicationId === null => null,
            str_starts_with($applicationId, 'sandbox-sq0idb-') => self::Sandbox,
            str_starts_with($applicationId, 'sq0idp-') => self::Production,
            default => null,
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
