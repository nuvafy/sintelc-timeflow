<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceSyncItem extends Model
{
    protected $fillable = [
        'device_sync_batch_id',
        'biometric_source_id',
        'device_user_assignment_id',
        'factorial_employee_id',
        'action',
        'pin',
        'name',
        'syncs_with_factorial',
        'status',
        'error',
    ];

    protected $casts = [
        'syncs_with_factorial' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeviceSyncBatch::class, 'device_sync_batch_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class, 'biometric_source_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceUserAssignment::class, 'device_user_assignment_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }
}
