<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientAttendanceConfig extends Model
{
    protected $fillable = [
        'client_id',
        'checkin_id',
        'checkout_id',
        'has_breaks',
        'breakin_id',
        'breakout_id',
    ];

    protected $casts = [
        'has_breaks' => 'boolean',
    ];

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function resolveCheckType(string $biometricStatusId): ?string
    {
        return match ($biometricStatusId) {
            $this->checkin_id  => 'check_in',
            $this->checkout_id => 'check_out',
            $this->breakin_id  => 'break_in',
            $this->breakout_id => 'break_out',
            default            => null,
        };
    }
}
