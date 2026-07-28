<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceUserAssignment extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_source_id',
        'biometric_user_sync_id',
        'factorial_employee_id',
        'pin',
        'name',
        'desired_state',
        'sync_status',
        'verification_method',
        'confirmed_at',
        'last_error',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class, 'biometric_source_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(BiometricUserSync::class, 'biometric_user_sync_id');
    }

    public function factorialEmployee(): BelongsTo
    {
        return $this->belongsTo(FactorialEmployee::class);
    }

    public function syncItems(): HasMany
    {
        return $this->hasMany(DeviceSyncItem::class);
    }
}
