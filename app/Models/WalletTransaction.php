<?php

namespace App\Models;

use App\Enums\WalletTransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'account_id',
        'reference',
        'amount',
        'status',
        'gateway_transaction_id',
        'journal_id',
        'completed_at',
        'abandoned_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => WalletTransactionStatus::class,
            'completed_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function isPending(): bool
    {
        return $this->status === WalletTransactionStatus::Pending;
    }

    public function scopePending($query)
    {
        return $query->where('status', WalletTransactionStatus::Pending);
    }

    public function scopeAbandoned($query)
    {
        return $query->where('status', WalletTransactionStatus::Abandoned);
    }
}
