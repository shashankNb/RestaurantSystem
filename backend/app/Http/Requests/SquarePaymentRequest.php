<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying a Square order: the token from Square's payment form (card, Apple Pay or Google
 * Pay), the order's tracking token to show it's the customer's own order, and an
 * Idempotency-Key header: a new random value per attempt, repeated on retries.
 */
class SquarePaymentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'tracking_token' => ['required', 'string', 'max:100'],
            'source_id' => ['required', 'string', 'max:1000'],
            'verification_token' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Send an Idempotency-Key header: a new random value for each payment attempt, repeated on retries.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }

    public function trackingToken(): string
    {
        return $this->string('tracking_token')->toString();
    }

    public function sourceId(): string
    {
        return $this->string('source_id')->toString();
    }

    public function verificationToken(): ?string
    {
        $token = $this->string('verification_token')->toString();

        return $token === '' ? null : $token;
    }
}
