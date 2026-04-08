<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricUserSync extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_provider_id',
        'factorial_employee_id',
        'external_employee_code',
        'provider_user_id',
        'sync_status',
        'sync_error',
        'last_attempt_at',
        'synced_at',
        'raw_request',
        'raw_response',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
        'raw_request' => 'array',
        'raw_response' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BiometricProvider::class);
    }

    public function factorialEmployee(): BelongsTo
    {
        return $this->belongsTo(FactorialEmployee::class);
    }
}
