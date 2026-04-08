<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricProvider extends Model
{
    protected $fillable = [
        'client_id',
        'vendor',
        'name',
        'base_url',
        'credentials',
        'status',
    ];

    protected $casts = [
        'credentials' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function biometricSources(): HasMany
    {
        return $this->hasMany(BiometricSource::class);
    }
}
