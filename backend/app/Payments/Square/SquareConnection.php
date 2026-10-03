<?php

namespace App\Payments\Square;

use Carbon\CarbonImmutable;

/**
 * A restaurant's Square account, connected to the platform's application: the tokens to act
 * for it. The access token lasts 30 days and is renewed with the refresh token.
 */
final readonly class SquareConnection
{
    public function __construct(
        public string $merchantId,
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
