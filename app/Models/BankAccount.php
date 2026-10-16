<?php

namespace App\Models;

use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'driver_id',
        'bank_code',
        'account_number',
        'account_name',
        'is_verified',
        'is_primary',
        'payouts_held_until',
        'verified_name',
        'name_match_status',
        'review_status',
        'review_note',
        'reviewed_by_admin_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_primary' => 'boolean',
            'payouts_held_until' => 'datetime',
            'name_match_status' => BankAccountNameMatch::class,
            'review_status' => BankAccountReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function maskedAccountNumber(): string
    {
        return str_repeat('*', max(0, strlen($this->account_number) - 4)).substr($this->account_number, -4);
    }
}
