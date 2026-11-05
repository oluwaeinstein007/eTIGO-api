<?php

use App\Support\PhoneNumber;

it('puts the usual ways of typing a Nigerian mobile into +234 form', function (string $typed) {
    expect(PhoneNumber::normalize($typed))->toBe('+2348076512144');
})->with([
    'already normalised' => '+2348076512144',
    'local 0 kept after the country code' => '+23408076512144',
    'local format' => '08076512144',
    'country code without plus' => '2348076512144',
    'international 00 prefix' => '002348076512144',
    'spaces and dashes' => '+234 807-651-2144',
    'brackets' => '(0807) 651 2144',
]);

it('leaves other numbers to validation', function (string $typed, string $expected) {
    expect(PhoneNumber::normalize($typed))->toBe($expected);
})->with([
    'a seeded +23400 number' => ['+2340000000001', '+2340000000001'],
    'another country' => ['+44 7911 123456', '+447911123456'],
    'too short' => ['0807651214', '0807651214'],
]);
