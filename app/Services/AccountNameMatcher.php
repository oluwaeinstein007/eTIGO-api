<?php

namespace App\Services;

/**
 * Compares the name on a bank account with a person's verified name the way a
 * reviewer would: word order, case, punctuation and titles are ignored, and a
 * one-letter spelling difference in a longer name still counts. A match needs
 * the surname plus at least one given name (first or middle).
 */
class AccountNameMatcher
{
    private const IGNORED_WORDS = [
        'MR', 'MRS', 'MS', 'MISS', 'DR', 'PROF', 'ENGR', 'BARR', 'CHIEF', 'ALHAJI', 'ALHAJA',
        'HAJIA', 'PASTOR', 'REV', 'SIR', 'MADAM', 'HRH', 'OBA', 'NNA', 'ARC', 'PHARM', 'HON',
    ];

    /**
     * @param  list<string>  $givenNames  first name, then any middle names
     */
    public function matches(string $accountName, array $givenNames, ?string $surname): bool
    {
        $accountWords = $this->words($accountName);
        $given = array_values(array_filter(array_merge(...array_map(fn ($name) => $this->words((string) $name), $givenNames))));
        $surnameWords = $this->words((string) $surname);

        if ($accountWords === [] || $given === []) {
            return false;
        }

        // Without a surname on record, two given names must appear instead.
        if ($surnameWords === []) {
            return count(array_filter($given, fn ($word) => $this->contains($accountWords, $word))) >= min(2, count($given));
        }

        $surnameFound = (bool) array_filter($surnameWords, fn ($word) => $this->contains($accountWords, $word));
        $givenFound = (bool) array_filter($given, fn ($word) => $this->contains($accountWords, $word));

        return $surnameFound && $givenFound;
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z]+/', ' ', $this->ascii($name)));

        return array_values(array_filter(
            explode(' ', $normalised),
            fn (string $word) => strlen($word) > 1 && ! in_array($word, self::IGNORED_WORDS, true),
        ));
    }

    /**
     * @param  list<string>  $words
     */
    private function contains(array $words, string $word): bool
    {
        foreach ($words as $candidate) {
            if ($candidate === $word) {
                return true;
            }

            if (min(strlen($candidate), strlen($word)) >= 5 && levenshtein($candidate, $word) <= 1) {
                return true;
            }
        }

        return false;
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }
}
