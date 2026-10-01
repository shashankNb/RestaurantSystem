<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:40'],
            'postcode' => ['required', 'string', 'regex:/^\d{4}$/'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'line1.required' => 'Enter the street address.',
            'suburb.required' => 'Enter the suburb.',
            'state.required' => 'Enter the state, like VIC.',
            'postcode.required' => 'Enter the postcode.',
            'postcode.regex' => 'Enter a 4-digit postcode, like 3006.',
        ];
    }
}
