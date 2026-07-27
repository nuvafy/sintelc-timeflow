<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceSyncBatch;
use App\Models\User;

class DeviceEmployeeRefreshService
{
    public function __construct(private readonly DeviceSyncBatchService $batches) {}

    public function refresh(BiometricSource $source, ?User $creator = null): array
    {
        $inventory = collect($source->device_users ?? [])
            ->filter(fn($user) => isset($user['pin']))
            ->keyBy(fn($user) => (string) $user['pin']);

        $assignments = $source->assignments()
            ->where('desired_state', 'present')
            ->with(['identity.factorialEmployee', 'syncItems'])
            ->get();

        $decisions = [];
        $unchanged = 0;
        $active = 0;

        foreach ($assignments as $assignment) {
            $identity = $assignment->identity;
            $systemName = $identity?->factorialEmployee?->full_name
                ?? $identity?->local_name
                ?? $assignment->name;
            $deviceName = $this->deviceName($systemName);
            $reportedName = data_get($inventory->get((string) $assignment->pin), 'name');

            if ($reportedName !== null && $this->comparableName($reportedName) === $this->comparableName($deviceName)) {
                $unchanged++;
                continue;
            }

            $hasActiveOperation = in_array(
                $assignment->sync_status,
                ['planned', 'queued', 'sent', 'awaiting_verification'],
                true
            ) || $assignment->syncItems->contains(
                fn($item) => in_array($item->status, ['planned', 'queued', 'sent', 'acknowledged'], true)
            );

            if ($hasActiveOperation) {
                $active++;
                continue;
            }

            $decisions[] = [
                'action' => $identity?->factorial_employee_id ? 'add_factorial' : 'add_local',
                'pin' => (string) $assignment->pin,
                'name' => $systemName,
                'factorial_employee_id' => $identity?->factorial_employee_id,
            ];
        }

        $batch = $decisions === []
            ? null
            : $this->batches->create($source, $decisions, $creator, 'refresh', 'device_refresh');

        return [
            'assigned' => $assignments->count(),
            'queued' => count($decisions),
            'unchanged' => $unchanged,
            'active' => $active,
            'batch' => $batch,
        ];
    }

    private function deviceName(mixed $name): string
    {
        return mb_substr(
            preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $name)) ?? '',
            0,
            24
        );
    }

    private function comparableName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return mb_strtoupper($normalized);
    }
}
