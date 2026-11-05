<?php

namespace App\Support;

/**
 * Puts a phone number someone typed into the +234… form phones are stored
 * and texted in.
 */
class PhoneNumber
{
    /**
     * Separators are dropped, and the usual ways of typing a Nigerian mobile
     * number become +234 followed by its 10 digits: 0803…, 234803…, and
     * +2340803… (the local 0 kept after the country code, which an app's
     * country picker produces and SMS cannot be delivered to). Anything else
     * is returned with only the separators removed, for validation to judge.
     */
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s\-().]+/', '', $phone);

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (preg_match('/^(?:\+?2340?|0)(\d{10})$/', $phone, $matches) === 1) {
            return '+234'.$matches[1];
        }

        return $phone;
    }
}
