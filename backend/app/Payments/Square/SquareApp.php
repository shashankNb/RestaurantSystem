<?php

namespace App\Payments\Square;

use App\Enums\SquareEnvironment;

/**
 * The platform's own Square application, which each restaurant connects its Square account
 * to ("Connect with Square"). One per environment, set in backend/.env; without one, the
 * back office doesn't offer that environment.
 */
final readonly class SquareApp
{
    /** What the platform asks each restaurant's Square account for: payments, and its name and locations. */
    public const SCOPES = ['MERCHANT_PROFILE_READ', 'PAYMENTS_READ', 'PAYMENTS_WRITE'];

    public function __construct(
        public SquareEnvironment $environment,
        /** Public: the apps and the website start Square's payment forms with it. */
        public string $applicationId,
        public string $applicationSecret,
        public ?string $webhookSignatureKey,
    ) {}

    /** The application for the environment, or null if its credentials aren't set. */
    public static function for(SquareEnvironment $environment): ?self
    {
        $id = config("services.square.{$environment->value}.application_id");
        $secret = config("services.square.{$environment->value}.application_secret");
        $signatureKey = config("services.square.{$environment->value}.webhook_signature_key");

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        return new self($environment, $id, $secret, is_string($signatureKey) && $signatureKey !== '' ? $signatureKey : null);
    }

    /** Square's page where the owner signs in and approves the connection. */
    public function authorizeUrl(string $state): string
    {
        return $this->environment->host().'/oauth2/authorize?'.http_build_query([
            'client_id' => $this->applicationId,
            'scope' => implode(' ', self::SCOPES),
            // Always ask which account to connect, rather than using whoever's signed in.
            'session' => 'false',
            'state' => $state,
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
