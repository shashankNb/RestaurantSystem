<?php

namespace App\Payments\Square;

/**
 * One of a Square account's locations (a shop, in Square's terms). Payments are taken at a
 * location, and only in its currency.
 */
final readonly class SquareLocation
{
    public function __construct(
        public string $id,
        public string $name,
        public string $currency,
        public bool $active,
    ) {}

    /**
     * @return array{id: string, name: string, currency: string, active: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'currency' => $this->currency, 'active' => $this->active];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            currency: (string) ($data['currency'] ?? ''),
            active: (bool) ($data['active'] ?? false),
        );
    }
}
