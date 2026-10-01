<?php

namespace App\Http\Requests;

use App\Enums\FulfilmentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SlotsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fulfilment_type' => ['required', Rule::enum(FulfilmentType::class)],
            // Optional for delivery: the delivery time depends on the zone.
            'postcode' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postcode.regex' => 'Enter a 4-digit postcode, like 3006.',
        ];
    }

    public function fulfilmentType(): FulfilmentType
    {
        return FulfilmentType::from($this->string('fulfilment_type')->toString());
    }
}
