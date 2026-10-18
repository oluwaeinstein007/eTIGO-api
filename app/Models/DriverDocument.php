<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'uploaded_by',
        'type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'expires_at',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'file_size' => 'integer',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
