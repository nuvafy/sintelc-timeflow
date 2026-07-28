<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceSyncBatch extends Model
{
    protected $fillable = [
        'uuid',
        'client_id',
        'created_by',
        'type',
        'origin',
        'status',
        'total_items',
        'confirmed_items',
        'failed_items',
        'pending_items',
        'options',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeviceSyncItem::class);
    }
}
