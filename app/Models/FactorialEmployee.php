<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactorialEmployee extends Model
{
    protected $fillable = [
        'client_id',
        'factorial_connection_id',
        'factorial_id',
        'access_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'login_email',
        'company_id',
        'company_identifier',
        'location_id',
        'active',
        'attendable',
        'is_terminating',
        'terminated_on',
        'factorial_created_at',
        'factorial_updated_at',
        'raw_payload',
    ];

    protected $casts = [
        'active'               => 'boolean',
        'attendable'           => 'boolean',
        'is_terminating'       => 'boolean',
        'terminated_on'        => 'datetime',
        'factorial_created_at' => 'datetime',
        'factorial_updated_at' => 'datetime',
        'raw_payload'          => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FactorialConnection::class, 'factorial_connection_id');
    }
}
