<?php

namespace App\Http\Requests;

use App\Data\Customer;
use App\Enums\FulfilmentType;
use App\Models\Restaurant;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A cart (as for a quote) plus who it's for and where it goes. The Idempotency-Key header
 * is required: the app sends a new random key per order and repeats it on retries.
 */
class StoreOrderRequest extends QuoteRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{8,}$/', $this->callableByDriver()],
            'customer.email' => ['required', 'string', 'email', 'max:255'],
            'delivery' => ['nullable', 'array', 'required_if:fulfilment_type,delivery'],
            'delivery.line1' => ['nullable', 'required_if:fulfilment_type,delivery', 'string', 'max:255'],
            'delivery.line2' => ['nullable', 'string', 'max:255'],
            'delivery.suburb' => ['nullable', 'required_if:fulfilment_type,delivery', 'string', 'max:100'],
            'delivery.state' => ['nullable', 'string', 'max:40'],
            'delivery.instructions' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'push_token' => ['nullable', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'idempotency_key.required' => 'Send an Idempotency-Key header: a new random value for each order, repeated on retries.',
            'customer.name.required' => 'Enter your name so the restaurant knows whose order it is.',
            'customer.phone.required' => 'Enter a phone number in case the restaurant needs to reach you.',
            'customer.phone.regex' => 'Enter a phone number with at least 8 digits, like 0412 345 678.',
            'customer.email.required' => 'Enter your email address for your receipt.',
            'customer.email.email' => 'Enter an email address like name@example.com.',
            'delivery.required_if' => 'Enter the delivery address.',
            'delivery.line1.required_if' => 'Enter the street address for delivery.',
            'delivery.suburb.required_if' => 'Enter the suburb for delivery.',
            'push_token.regex' => 'That isn’t an Expo push token.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);

        $customer = $this->input('customer');

        if (is_array($customer) && isset($customer['email']) && is_string($customer['email'])) {
            $this->merge(['customer' => [...$customer, 'email' => mb_strtolower(trim($customer['email']))]]);
        }
    }

    /**
     * DoorDash's driver calls the customer, so DoorDash needs their number with its country
     * code: it has to be one that can be written that way.
     */
    private function callableByDriver(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $restaurant = $this->route('restaurant');

            if ($this->input('fulfilment_type') === FulfilmentType::Delivery->value
                && $restaurant instanceof Restaurant
                && $restaurant->deliversWithDoorDash()
                && PhoneNumber::e164(is_string($value) ? $value : null) === null) {
                $fail('Enter a phone number the delivery driver can call, like 0412 345 678.');
            }
        };
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }

    public function customer(): Customer
    {
        /** @var array{name: string, phone: string, email: string} $customer */
        $customer = $this->validated('customer');
        /** @var array{line1?: string|null, line2?: string|null, suburb?: string|null, state?: string|null, instructions?: string|null}|null $delivery */
        $delivery = $this->validated('delivery');
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return new Customer(
            name: trim($customer['name']),
            phone: trim($customer['phone']),
            email: $customer['email'],
            deliveryLine1: $text($delivery['line1'] ?? null),
            deliveryLine2: $text($delivery['line2'] ?? null),
            deliverySuburb: $text($delivery['suburb'] ?? null),
            deliveryState: $text($delivery['state'] ?? null),
            deliveryInstructions: $text($delivery['instructions'] ?? null),
            notes: $text($this->validated('notes')),
            pushToken: $text($this->validated('push_token')),
        );
    }

    /**
     * Identifies the request, so a reused Idempotency-Key with a different order can be
     * refused. The key itself and the push token (which a retry may add) aren't part of it.
     */
    public function fingerprint(): string
    {
        $data = $this->validated();
        unset($data['idempotency_key'], $data['push_token']);

        return hash('sha256', (string) json_encode(self::sorted($data)));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sorted(array $data): array
    {
        ksort($data);

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sorted($value) : $value, $data);
    }
}
