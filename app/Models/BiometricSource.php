<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\DeviceCommand;

class BiometricSource extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_provider_id',
        'factorial_location_id',
        'name',
        'vendor',
        'device_code',
        'serial_number',
        'site_name',
        'settings',
        'status',
        'last_ping_at',
        'device_users',
        'device_users_fetched_at',
    ];

    protected $casts = [
        'settings'                => 'array',
        'device_users'            => 'array',
        'last_ping_at'            => 'datetime',
        'device_users_fetched_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BiometricProvider::class, 'biometric_provider_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(FactorialLocation::class, 'factorial_location_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function isAssigned(): bool
    {
        return $this->client_id !== null;
    }
}
