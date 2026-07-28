<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    protected $fillable = [
        'biometric_source_id',
        'device_sync_item_id',
        'command_seq',
        'command_type',
        'idempotency_key',
        'payload',
        'status',
        'sent_at',
        'acknowledged_at',
        'device_response',
    ];

    protected $casts = [
        'sent_at'          => 'datetime',
        'acknowledged_at'  => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class, 'biometric_source_id');
    }

    public function syncItem(): BelongsTo
    {
        return $this->belongsTo(DeviceSyncItem::class, 'device_sync_item_id');
    }
}
