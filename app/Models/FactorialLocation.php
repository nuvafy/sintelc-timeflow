<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactorialLocation extends Model
{
    protected $fillable = [
        'client_id',
        'factorial_connection_id',
        'factorial_location_id',
        'factorial_company_id',
        'name',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FactorialConnection::class, 'factorial_connection_id');
    }

    public function biometricSources(): HasMany
    {
        return $this->hasMany(BiometricSource::class);
    }
}
