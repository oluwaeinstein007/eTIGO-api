<?php

namespace App\Enums;

/**
 * When in the day a promo applies. Stored as the peak_only / off_peak_only
 * flags; peak hours are set in config/promo.php, in the city's local time.
 */
enum PromoTiming: string
{
    case AnyTime = 'any_time';
    case PeakOnly = 'peak_only';
    case OffPeakOnly = 'off_peak_only';

    public function label(): string
    {
        return match ($this) {
            self::AnyTime => 'Any time',
            self::PeakOnly => 'Peak hours only',
            self::OffPeakOnly => 'Off-peak hours only',
        };
    }

    /**
     * @return array{peak_only: bool, off_peak_only: bool}
     */
    public function flags(): array
    {
        return [
            'peak_only' => $this === self::PeakOnly,
            'off_peak_only' => $this === self::OffPeakOnly,
        ];
    }

    public static function fromFlags(bool $peakOnly, bool $offPeakOnly): self
    {
        return match (true) {
            $peakOnly => self::PeakOnly,
            $offPeakOnly => self::OffPeakOnly,
            default => self::AnyTime,
        };
    }
}
