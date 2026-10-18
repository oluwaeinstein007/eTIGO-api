<?php

namespace App\Enums;

enum DisputeEvidenceType: string
{
    case Photo = 'photo';
    case Audio = 'audio';
    case Document = 'document';

    public static function fromMime(?string $mime): self
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => self::Photo,
            str_starts_with((string) $mime, 'audio/'), str_starts_with((string) $mime, 'video/') => self::Audio,
            default => self::Document,
        };
    }
}
