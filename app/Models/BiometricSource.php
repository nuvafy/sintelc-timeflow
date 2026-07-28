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
        'device_code',
        'serial_number',
        'site_name',
        'settings',
        'status',
        'onboarding_status',
        'onboarding_started_at',
        'onboarding_completed_at',
        'onboarding_error',
        'last_ping_at',
        'device_users',
        'device_users_fetched_at',
        'last_inventory_at',
        'push_version',
        'push_protocol_profile',
        'push_protocol_source',
        'push_protocol_detected_at',
        'device_name',
        'device_firmware',
        'reported_user_count',
        'reported_fingerprint_count',
        'reported_face_count',
        'device_info_reported_at',
        'device_info_payload',
        'biodata_cache',
        'biodata_cached_at',
        'clone_target_id',
    ];

    protected $casts = [
        'settings'                => 'array',
        'device_users'            => 'array',
        'last_ping_at'            => 'datetime',
        'device_users_fetched_at' => 'datetime',
        'last_inventory_at'       => 'datetime',
        'push_protocol_detected_at' => 'datetime',
        'device_info_reported_at' => 'datetime',
        'device_info_payload' => 'array',
        'onboarding_started_at'   => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'biodata_cache'           => 'array',
        'biodata_cached_at'       => 'datetime',
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

    public function inventorySnapshots(): HasMany
    {
        return $this->hasMany(DeviceInventorySnapshot::class);
    }

    public function userAssignments(): HasMany
    {
        return $this->hasMany(DeviceUserAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->userAssignments();
    }

    public function syncItems(): HasMany
    {
        return $this->hasMany(DeviceSyncItem::class);
    }

    public function isAssigned(): bool
    {
        return $this->client_id !== null;
    }
}
