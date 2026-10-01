<?php

namespace App\Http\Requests;

use App\Data\Cart;
use App\Enums\FulfilmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a cart. Whether it can actually be ordered (sold-out items, option rules,
 * opening hours, delivery area, promo codes) is PricingService's job, and comes back in
 * the quote rather than as a validation error. Prices sent by the app are ignored.
 */
class QuoteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fulfilment_type' => ['required', Rule::enum(FulfilmentType::class)],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            // Not `distinct`: it compares across every line, so two lines couldn't both choose
            // "Chicken". A repeated ID within one line counts once.
            'items.*.modifier_option_ids' => ['sometimes', 'nullable', 'array', 'max:30'],
            'items.*.modifier_option_ids.*' => ['integer'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
            'postcode' => ['nullable', 'required_if:fulfilment_type,delivery', 'string', 'regex:/^\d{4}$/'],
            'scheduled_for' => ['nullable', 'date'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Your cart is empty. Add something from the menu first.',
            'items.min' => 'Your cart is empty. Add something from the menu first.',
            'items.max' => 'That’s more lines than one order can take. Combine items or split the order.',
            'items.*.quantity.max' => 'You can order up to 50 of an item. For bigger orders, call the restaurant.',
            'postcode.required_if' => 'Enter your postcode so we can check we deliver to you.',
            'postcode.regex' => 'Enter a 4-digit postcode, like 3006.',
            'scheduled_for.date' => 'Choose one of the times offered.',
        ];
    }

    public function cart(): Cart
    {
        /** @var array{fulfilment_type: string, items: list<array{menu_item_id: int|string, quantity: int|string, modifier_option_ids?: list<int|string>|null, notes?: string|null}>, postcode?: string|null, scheduled_for?: string|null, promo_code?: string|null} $validated */
        $validated = $this->validated();

        return Cart::fromValidated($validated);
    }
}
