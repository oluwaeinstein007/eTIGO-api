<?php

namespace App\Support;

/**
 * Turns what an admin typed in a list's search box into the forms the
 * queries need.
 */
class SearchTerm
{
    /**
     * The term as a "contains" LIKE pattern, with LIKE's own wildcards
     * escaped so "%" or "_" match only themselves.
     */
    public static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /**
     * The digits to look for in a stored phone, or null when the term is not
     * a phone number. Phones are stored as +234…; a leading 0 (local format)
     * is dropped so "0803 123 4567" matches too. At least 4 digits.
     */
    public static function phoneDigits(string $term): ?string
    {
        if (preg_match('/^\+?[\d\s\-().]+$/', $term) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $term);

        if (strlen($digits) < 4) {
            return null;
        }

        return str_starts_with($digits, '0') ? substr($digits, 1) : $digits;
    }

    /**
     * The term's letters and digits only, upper-case, to match a plate typed
     * with or without spaces and dashes ("LAG 100 AA" finds "LAG-100AA").
     * Null when nothing is left.
     */
    public static function plateKey(string $term): ?string
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $term));

        return $key === '' ? null : $key;
    }

    /**
     * SQL for a plate column with everything but letters and digits removed,
     * upper-cased, to compare with plateKey().
     */
    public static function plateColumn(string $column): string
    {
        return "upper(regexp_replace({$column}, '[^A-Za-z0-9]', '', 'g'))";
    }
}
