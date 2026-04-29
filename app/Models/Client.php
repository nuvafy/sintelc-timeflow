<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'oauth_client_id',
        'oauth_client_secret',
        'hq_address',
        'contact_email',
    ];

    public function factorialConnections(): HasMany
    {
        return $this->hasMany(FactorialConnection::class);
    }

    public function biometricSources(): HasMany
    {
        return $this->hasMany(BiometricSource::class);
    }

    public function biometricProviders(): HasMany
    {
        return $this->hasMany(BiometricProvider::class);
    }

    public function factorialLocations(): HasMany
    {
        return $this->hasMany(FactorialLocation::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
