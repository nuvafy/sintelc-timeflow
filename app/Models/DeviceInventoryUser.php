<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceInventoryUser extends Model
{
    protected $fillable = [
        'device_inventory_snapshot_id',
        'biometric_source_id',
        'pin',
        'name',
        'card',
        'privilege',
        'protocol',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(DeviceInventorySnapshot::class, 'device_inventory_snapshot_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class, 'biometric_source_id');
    }
}
