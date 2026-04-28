<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    protected $fillable = [
        'biometric_source_id',
        'command_seq',
        'command_type',
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
}
