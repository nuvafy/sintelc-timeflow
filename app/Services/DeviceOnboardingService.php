<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;

class DeviceOnboardingService
{
    public function requestInventory(BiometricSource $source): DeviceCommand
    {
        return DB::transaction(function () use ($source) {
            $lockedSource = BiometricSource::query()->lockForUpdate()->findOrFail($source->id);

            $existing = DeviceCommand::query()
                ->where('biometric_source_id', $lockedSource->id)
                ->where('command_type', 'query_users')
                ->whereIn('status', ['pending', 'sent'])
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $sequence = (int) DeviceCommand::where('biometric_source_id', $lockedSource->id)
                ->max('command_seq') + 1;

            $command = DeviceCommand::create([
                'biometric_source_id' => $lockedSource->id,
                'command_seq' => $sequence,
                'command_type' => 'query_users',
                'idempotency_key' => "onboarding-inventory:{$lockedSource->id}:{$sequence}",
                'payload' => $this->inventoryPayload($lockedSource),
                'status' => 'pending',
            ]);

            $lockedSource->update([
                'onboarding_status' => 'querying_users',
                'onboarding_started_at' => $lockedSource->onboarding_started_at ?? now(),
                'onboarding_completed_at' => null,
                'onboarding_error' => null,
            ]);

            return $command;
        });
    }

    private function inventoryPayload(BiometricSource $source): string
    {
        $deviceName = strtolower((string) $source->device_name);

        return str_contains($deviceName, 'senseface')
            ? 'DATA QUERY user'
            : 'DATA QUERY USERINFO';
    }
}
