<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricProvider extends Model
{
    protected $fillable = [
        'client_id',
        'factorial_connection_id',
        'vendor',
        'status',
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

    public function userSyncs(): HasMany
    {
        return $this->hasMany(BiometricUserSync::class);
    }
}
