<?php

namespace App\Rules;

use App\Models\Restaurant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A restaurant's website domain, such as order.example.com.au. Whatever is typed or pasted
 * (capitals, https://, the address of a page) is checked as the lower-case bare domain it's
 * saved as: browsers send it in lower case, and the API's CORS check compares it exactly.
 */
final class WebsiteDomain implements ValidationRule
{
    public function __construct(private readonly ?Restaurant $ignore = null) {}

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // A pasted address: only its host counts.
        $host = parse_url(str_contains($value, '://') ? $value : "https://{$value}", PHP_URL_HOST);

        return strtolower(is_string($host) && $host !== '' ? rtrim($host, '.') : $value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = self::normalize(is_string($value) ? $value : null);

        if ($domain === null) {
            return;
        }

        if (preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) !== 1) {
            $fail('Enter just the website’s domain, like order.example.com.au.');

            return;
        }

        $taken = Restaurant::query()->where('custom_domain', $domain);

        if ($this->ignore !== null) {
            $taken->whereKeyNot($this->ignore->getKey());
        }

        if ($taken->exists()) {
            $fail('Another restaurant already uses this domain.');
        }
    }
}
