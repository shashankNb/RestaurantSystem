<?php

use App\Support\PhoneNumber;

it('writes phone numbers in E.164, the way couriers need them', function (string $number, string $e164) {
    expect(PhoneNumber::e164($number))->toBe($e164);
})->with([
    'a mobile' => ['0491 570 110', '+61491570110'],
    'a landline' => ['(03) 5550 1234', '+61355501234'],
    'with the country code' => ['+61 491 570 110', '+61491570110'],
    'with the country code but no plus' => ['61491570110', '+61491570110'],
    'dialled from overseas' => ['0061 3 5550 1234', '+61355501234'],
    'from another country' => ['+64 21 123 4567', '+64211234567'],
]);

it('refuses what can’t be a phone number', function (?string $number) {
    expect(PhoneNumber::e164($number))->toBeNull();
})->with([
    'nothing' => [null],
    'empty' => [''],
    'too short' => ['0412'],
    'no area code or country code' => ['5550 1234'],
    'too long' => ['+61 491 570 110 491 570'],
]);
