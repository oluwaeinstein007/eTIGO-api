<?php

use App\Services\AccountNameMatcher;

it('matches a bank account name to a verified name like a reviewer would', function (string $accountName, array $givenNames, string $surname, bool $expected) {
    expect((new AccountNameMatcher)->matches($accountName, $givenNames, $surname))->toBe($expected);
})->with([
    'same order' => ['ADA OBI', ['Ada'], 'Obi', true],
    'surname first with a middle name' => ['OBI ADA CHIOMA', ['Ada'], 'Obi', true],
    'title and punctuation ignored' => ['MRS. ADAEZE  OBI-', ['Adaeze'], 'Obi', true],
    'middle name instead of first name' => ['CHIOMA OBI', ['Ada', 'Chioma'], 'Obi', true],
    'one-letter spelling difference in a long name' => ['MUHAMMED BELLO', ['Mohammed'], 'Bello', true],
    'accented letters' => ['ADÉ OBI', ['Ade'], 'Obi', true],
    'lower case' => ['ada obi', ['ADA'], 'OBI', true],
    'surname only' => ['EMEKA OBI', ['Ada'], 'Obi', false],
    'first name only' => ['ADA NWOSU', ['Ada'], 'Obi', false],
    'someone else' => ['JOHN DOE', ['Ada'], 'Obi', false],
    'initial only' => ['A OBI', ['Ada'], 'Obi', false],
    'short names need an exact match' => ['ADE OBA', ['Ada'], 'Obi', false],
    'business name' => ['OBI VENTURES LTD', ['Ada'], 'Obi', false],
]);

it('needs two given names to match when no surname is on record', function () {
    $matcher = new AccountNameMatcher;

    expect($matcher->matches('ADA CHIOMA', ['Ada', 'Chioma'], null))->toBeTrue()
        ->and($matcher->matches('ADA NWOSU', ['Ada', 'Chioma'], null))->toBeFalse();
});

it('never matches an empty name', function () {
    $matcher = new AccountNameMatcher;

    expect($matcher->matches('', ['Ada'], 'Obi'))->toBeFalse()
        ->and($matcher->matches('ADA OBI', [], ''))->toBeFalse();
});
