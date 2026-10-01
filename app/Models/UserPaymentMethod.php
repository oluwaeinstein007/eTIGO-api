<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'gateway_token',
        'card_brand',
        'card_last_four',
        'card_expiry_month',
        'card_expiry_year',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'card_expiry_month' => 'integer',
            'card_expiry_year' => 'integer',
        ];
    }

    protected $hidden = [
        'gateway_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
