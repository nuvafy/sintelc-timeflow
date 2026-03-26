<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_source_id',
        'employee_mapping_id',
        'external_event_id',
        'employee_code',
        'check_type',
        'occurred_at',
        'raw_payload',
        'processed_at',
        'sync_status',
        'sync_error',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function biometricSource(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class);
    }

    public function employeeMapping(): BelongsTo
    {
        return $this->belongsTo(EmployeeMapping::class);
    }
}
