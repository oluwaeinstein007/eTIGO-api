<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'reference',
        'description',
        'idempotency_key',
        'metadata',
        'posted_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function totalDebits(): int
    {
        return (int) $this->entries()->where('type', 'debit')->sum('amount');
    }

    public function totalCredits(): int
    {
        return (int) $this->entries()->where('type', 'credit')->sum('amount');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }
}
