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
    ];

    public function factorialConnections(): HasMany
    {
        return $this->hasMany(FactorialConnection::class);
    }

    public function biometricSources(): HasMany
    {
        return $this->hasMany(BiometricSource::class);
    }

    public function employeeMappings(): HasMany
    {
        return $this->hasMany(EmployeeMapping::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
