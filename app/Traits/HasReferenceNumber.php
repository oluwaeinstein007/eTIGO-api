<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait HasReferenceNumber
{
    public static function bootHasReferenceNumber(): void
    {
        static::creating(function ($model) {
            if (empty($model->reference_number) && $model->shouldGenerateReferenceNumber()) {
                $model->reference_number = static::generateReferenceNumber();
            }
        });
    }

    public function shouldGenerateReferenceNumber(): bool
    {
        return true;
    }

    public static function generateReferenceNumber(): string
    {
        $prefix = static::referencePrefix();
        $table = (new static)->getTable();

        $maxNum = DB::table($table)
            ->whereNotNull('reference_number')
            ->where('reference_number', 'like', "{$prefix}%")
            ->selectRaw("MAX(CAST(REPLACE(reference_number, ?, '') AS INTEGER)) as max_num", [$prefix])
            ->value('max_num') ?? 0;

        return $prefix . str_pad($maxNum + 1, 6, '0', STR_PAD_LEFT);
    }

    abstract protected static function referencePrefix(): string;
}
