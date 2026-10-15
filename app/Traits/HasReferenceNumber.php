<?php

namespace App\Traits;

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
        $model = new static;
        $table = $model->getTable();
        $connection = $model->getConnection();

        // Postgres hands out each sequence value exactly once, so records
        // created at the same moment never draw the same number.
        if ($connection->getDriverName() === 'pgsql') {
            $nextNum = (int) $connection->selectOne('SELECT nextval(?) AS value', ["{$table}_reference_number_seq"])->value;
        } else {
            $maxNum = (int) $connection->table($table)
                ->whereNotNull('reference_number')
                ->where('reference_number', 'like', "{$prefix}%")
                ->selectRaw("MAX(CAST(REPLACE(reference_number, ?, '') AS INTEGER)) as max_num", [$prefix])
                ->value('max_num');
            $nextNum = $maxNum + 1;
        }

        $referenceNumber = $prefix.str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        while ($connection->table($table)->where('reference_number', $referenceNumber)->exists()) {
            $nextNum++;
            $referenceNumber = $prefix.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        return $referenceNumber;
    }

    abstract protected static function referencePrefix(): string;
}
