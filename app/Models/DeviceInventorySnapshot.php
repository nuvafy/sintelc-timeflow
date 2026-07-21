<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceInventorySnapshot extends Model
{
    protected $fillable = [
        'biometric_source_id',
        'origin',
        'status',
        'user_count',
        'captured_at',
        'metadata',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class, 'biometric_source_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(DeviceInventoryUser::class);
    }
}
