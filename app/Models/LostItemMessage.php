<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message on a lost item report between passenger, driver and support,
 * or an internal support note (`is_internal`).
 */
class LostItemMessage extends Model
{
    use HasUuids;

    public const ROLE_PASSENGER = 'passenger';

    public const ROLE_DRIVER = 'driver';

    public const ROLE_SUPPORT = 'support';

    protected $fillable = [
        'lost_item_id',
        'sender_user_id',
        'sender_role',
        'body',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    public function lostItem(): BelongsTo
    {
        return $this->belongsTo(LostItem::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
