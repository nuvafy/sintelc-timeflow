<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactorialConnection extends Model
{
    protected $fillable = [
        'client_id',
        'factorial_company_id',
        'name',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_in',
        'expires_at',
        'resource_owner_type',
        'raw_response',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'raw_response' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FactorialLocation::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(FactorialEmployee::class);
    }

    public function biometricProviders(): HasMany
    {
        return $this->hasMany(BiometricProvider::class);
    }
}
