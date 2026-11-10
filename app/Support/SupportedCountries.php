<?php

namespace App\Support;

class SupportedCountries
{
    /**
     * Supported countries for phone number inputs across registration, login,
     * and third-party ride bookings.
     *
     * @return list<array{name: string, code: string, flag: string, flag_url: string, iso_code: string}>
     */
    public static function all(): array
    {
        return self::COUNTRIES;
    }

    /**
     * Find a supported country by dial code or ISO code.
     *
     * @return array{name: string, code: string, flag: string, flag_url: string, iso_code: string}|null
     */
    public static function find(string $code): ?array
    {
        $normalized = strtoupper(trim($code));

        foreach (self::COUNTRIES as $country) {
            if ($country['code'] === $code || strtoupper($country['iso_code']) === $normalized) {
                return $country;
            }
        }

        return null;
    }

    private const COUNTRIES = [
        [
            'name' => 'Nigeria',
            'code' => '+234',
            'flag' => '🇳🇬',
            'flag_url' => 'https://flagcdn.com/w40/ng.png',
            'iso_code' => 'NG',
        ]
        // [
        //     'name' => 'Ghana',
        //     'code' => '+233',
        //     'flag' => '🇬🇭',
        //     'flag_url' => 'https://flagcdn.com/w40/gh.png',
        //     'iso_code' => 'GH',
        // ],
        // [
        //     'name' => 'Kenya',
        //     'code' => '+254',
        //     'flag' => '🇰🇪',
        //     'flag_url' => 'https://flagcdn.com/w40/ke.png',
        //     'iso_code' => 'KE',
        // ],
        // [
        //     'name' => 'South Africa',
        //     'code' => '+27',
        //     'flag' => '🇿🇦',
        //     'flag_url' => 'https://flagcdn.com/w40/za.png',
        //     'iso_code' => 'ZA',
        // ],
        // [
        //     'name' => 'United Kingdom',
        //     'code' => '+44',
        //     'flag' => '🇬🇧',
        //     'flag_url' => 'https://flagcdn.com/w40/gb.png',
        //     'iso_code' => 'GB',
        // ],
        // [
        //     'name' => 'United States',
        //     'code' => '+1',
        //     'flag' => '🇺🇸',
        //     'flag_url' => 'https://flagcdn.com/w40/us.png',
        //     'iso_code' => 'US',
        // ],
        // [
        //     'name' => 'Canada',
        //     'code' => '+1',
        //     'flag' => '🇨🇦',
        //     'flag_url' => 'https://flagcdn.com/w40/ca.png',
        //     'iso_code' => 'CA',
        // ],
        // [
        //     'name' => 'United Arab Emirates',
        //     'code' => '+971',
        //     'flag' => '🇦🇪',
        //     'flag_url' => 'https://flagcdn.com/w40/ae.png',
        //     'iso_code' => 'AE',
        // ],
        // [
        //     'name' => 'Benin',
        //     'code' => '+229',
        //     'flag' => '🇧🇯',
        //     'flag_url' => 'https://flagcdn.com/w40/bj.png',
        //     'iso_code' => 'BJ',
        // ],
        // [
        //     'name' => 'Togo',
        //     'code' => '+228',
        //     'flag' => '🇹🇬',
        //     'flag_url' => 'https://flagcdn.com/w40/tg.png',
        //     'iso_code' => 'TG',
        // ],
        // [
        //     'name' => 'Cameroon',
        //     'code' => '+237',
        //     'flag' => '🇨🇲',
        //     'flag_url' => 'https://flagcdn.com/w40/cm.png',
        //     'iso_code' => 'CM',
        // ],
        // [
        //     'name' => "Côte d'Ivoire",
        //     'code' => '+225',
        //     'flag' => '🇨🇮',
        //     'flag_url' => 'https://flagcdn.com/w40/ci.png',
        //     'iso_code' => 'CI',
        // ],
        // [
        //     'name' => 'Rwanda',
        //     'code' => '+250',
        //     'flag' => '🇷🇼',
        //     'flag_url' => 'https://flagcdn.com/w40/rw.png',
        //     'iso_code' => 'RW',
        // ],
        // [
        //     'name' => 'Uganda',
        //     'code' => '+256',
        //     'flag' => '🇺🇬',
        //     'flag_url' => 'https://flagcdn.com/w40/ug.png',
        //     'iso_code' => 'UG',
        // ],
        // [
        //     'name' => 'Egypt',
        //     'code' => '+20',
        //     'flag' => '🇪🇬',
        //     'flag_url' => 'https://flagcdn.com/w40/eg.png',
        //     'iso_code' => 'EG',
        // ],
        // [
        //     'name' => 'Senegal',
        //     'code' => '+221',
        //     'flag' => '🇸🇳',
        //     'flag_url' => 'https://flagcdn.com/w40/sn.png',
        //     'iso_code' => 'SN',
        // ],
        // [
        //     'name' => 'Tanzania',
        //     'code' => '+255',
        //     'flag' => '🇹🇿',
        //     'flag_url' => 'https://flagcdn.com/w40/tz.png',
        //     'iso_code' => 'TZ',
        // ],
        // [
        //     'name' => 'Ethiopia',
        //     'code' => '+251',
        //     'flag' => '🇪🇹',
        //     'flag_url' => 'https://flagcdn.com/w40/et.png',
        //     'iso_code' => 'ET',
        // ],
        // [
        //     'name' => 'France',
        //     'code' => '+33',
        //     'flag' => '🇫🇷',
        //     'flag_url' => 'https://flagcdn.com/w40/fr.png',
        //     'iso_code' => 'FR',
        // ],
        // [
        //     'name' => 'Germany',
        //     'code' => '+49',
        //     'flag' => '🇩🇪',
        //     'flag_url' => 'https://flagcdn.com/w40/de.png',
        //     'iso_code' => 'DE',
        // ],
        // [
        //     'name' => 'India',
        //     'code' => '+91',
        //     'flag' => '🇮🇳',
        //     'flag_url' => 'https://flagcdn.com/w40/in.png',
        //     'iso_code' => 'IN',
        // ],
        // [
        //     'name' => 'China',
        //     'code' => '+86',
        //     'flag' => '🇨🇳',
        //     'flag_url' => 'https://flagcdn.com/w40/cn.png',
        //     'iso_code' => 'CN',
        // ],
        // [
        //     'name' => 'Australia',
        //     'code' => '+61',
        //     'flag' => '🇦🇺',
        //     'flag_url' => 'https://flagcdn.com/w40/au.png',
        //     'iso_code' => 'AU',
        // ],
        // [
        //     'name' => 'Brazil',
        //     'code' => '+55',
        //     'flag' => '🇧🇷',
        //     'flag_url' => 'https://flagcdn.com/w40/br.png',
        //     'iso_code' => 'BR',
        // ],
        // [
        //     'name' => 'Spain',
        //     'code' => '+34',
        //     'flag' => '🇪🇸',
        //     'flag_url' => 'https://flagcdn.com/w40/es.png',
        //     'iso_code' => 'ES',
        // ],
        // [
        //     'name' => 'Italy',
        //     'code' => '+39',
        //     'flag' => '🇮🇹',
        //     'flag_url' => 'https://flagcdn.com/w40/it.png',
        //     'iso_code' => 'IT',
        // ],
        // [
        //     'name' => 'Netherlands',
        //     'code' => '+31',
        //     'flag' => '🇳🇱',
        //     'flag_url' => 'https://flagcdn.com/w40/nl.png',
        //     'iso_code' => 'NL',
        // ],
        // [
        //     'name' => 'Ireland',
        //     'code' => '+353',
        //     'flag' => '🇮🇪',
        //     'flag_url' => 'https://flagcdn.com/w40/ie.png',
        //     'iso_code' => 'IE',
        // ],
        // [
        //     'name' => 'Saudi Arabia',
        //     'code' => '+966',
        //     'flag' => '🇸🇦',
        //     'flag_url' => 'https://flagcdn.com/w40/sa.png',
        //     'iso_code' => 'SA',
        // ],
        // [
        //     'name' => 'Qatar',
        //     'code' => '+974',
        //     'flag' => '🇶🇦',
        //     'flag_url' => 'https://flagcdn.com/w40/qa.png',
        //     'iso_code' => 'QA',
        // ],
    ];
}
