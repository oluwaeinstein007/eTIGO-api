<?php

it('returns supported countries list with name, code and flag', function () {
    $response = $this->getJson('/api/v1/countries');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'name',
                    'code',
                    'flag',
                    'flag_url',
                    'iso_code',
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data)->not->toBeEmpty();

    // Verify Nigeria is the first supported country
    $firstCountry = $data[0];
    expect($firstCountry['name'])->toBe('Nigeria')
        ->and($firstCountry['code'])->toBe('+234')
        ->and($firstCountry['flag'])->toBe('🇳🇬')
        ->and($firstCountry['flag_url'])->toBe('https://flagcdn.com/w40/ng.png')
        ->and($firstCountry['iso_code'])->toBe('NG');
});

it('also returns supported countries via lookup endpoint', function () {
    $response = $this->getJson('/api/v1/lookup/countries');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'name',
                    'code',
                    'flag',
                ],
            ],
        ]);
});
