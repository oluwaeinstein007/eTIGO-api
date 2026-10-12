<?php

namespace App\Traits;

trait HasReferenceNumber
{
    public static function bootHasReferenceNumber(): void
    {
        static::creating(function ($model) {
            if (empty($model->reference_number)) {
                $model->reference_number = static::generateReferenceNumber();
            }
        });
    }

    public static function generateReferenceNumber(): string
    {
        $prefix = static::referencePrefix();
        $lastNumber = static::whereNotNull('reference_number')
            ->selectRaw("MAX(CAST(REPLACE(reference_number, ?, '') AS INTEGER)) as max_num", [$prefix])
            ->value('max_num') ?? 0;

        return $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }

    abstract protected static function referencePrefix(): string;
}
