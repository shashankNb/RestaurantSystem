<?php

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * How ready a restaurant's Stripe account is for Apple Pay and Google Pay on its website:
 * whether each is switched on in the account, and whether the website's domain is registered
 * for them. The back office shows it as a checklist.
 */
final readonly class WalletSetup
{
    public function __construct(
        public bool $applePay,
        public bool $googlePay,
        /** The website's domain, or null when it has no public one yet (localhost). */
        public ?string $domain,
        /** Stripe has the domain registered, and Apple Pay and Google Pay work on it. */
        public bool $domainReady = false,
        /** Stripe's explanation when the domain isn't ready. */
        public ?string $domainProblem = null,
        public ?CarbonImmutable $checkedAt = null,
    ) {}

    public function ready(): bool
    {
        return $this->applePay && $this->googlePay && $this->domainReady;
    }

    /**
     * @return array{apple_pay: bool, google_pay: bool, domain: ?string, domain_ready: bool, domain_problem: ?string, checked_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'apple_pay' => $this->applePay,
            'google_pay' => $this->googlePay,
            'domain' => $this->domain,
            'domain_ready' => $this->domainReady,
            'domain_problem' => $this->domainProblem,
            'checked_at' => $this->checkedAt?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            applePay: (bool) ($data['apple_pay'] ?? false),
            googlePay: (bool) ($data['google_pay'] ?? false),
            domain: isset($data['domain']) ? (string) $data['domain'] : null,
            domainReady: (bool) ($data['domain_ready'] ?? false),
            domainProblem: isset($data['domain_problem']) ? (string) $data['domain_problem'] : null,
            checkedAt: isset($data['checked_at']) ? CarbonImmutable::parse((string) $data['checked_at']) : null,
        );
    }
}
