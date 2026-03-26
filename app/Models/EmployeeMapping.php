<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeMapping extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_source_id',
        'biometric_employee_code',
        'factorial_employee_id',
        'factorial_identifier',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function biometricSource(): BelongsTo
    {
        return $this->belongsTo(BiometricSource::class);
    }
}
