<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricSource extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'vendor',
        'base_url',
        'device_code',
        'site_name',
        'settings',
        'status',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employeeMappings(): HasMany
    {
        return $this->hasMany(EmployeeMapping::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
