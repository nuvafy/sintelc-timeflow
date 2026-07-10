<?php

namespace App\Console\Commands;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricUserSync;
use Illuminate\Console\Command;

class ResolvePendingAttendanceLogs extends Command
{
    protected $signature   = 'attendance:resolve-pending';
    protected $description = 'Resuelve attendance logs pendientes usando mapeos existentes y despacha sync jobs';

    public function handle(): int
    {
        $mappings = BiometricUserSync::whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        // Local-only employees: resolve pending logs without dispatching Factorial sync
        $localPins = BiometricUserSync::whereNull('factorial_employee_id')
            ->whereNotNull('local_name')
            ->pluck('external_employee_code');

        if ($localPins->isNotEmpty()) {
            AttendanceLog::whereIn('employee_code', $localPins)
                ->where('sync_status', 'pending')
                ->update(['sync_status' => 'local']);
        }

        if ($mappings->isEmpty()) {
            $this->info('Sin mapeos disponibles.');
            return self::SUCCESS;
        }

        // Pendientes sin mapeo → resolver y despachar
        $logs = AttendanceLog::whereNull('factorial_employee_id')
            ->whereIn('employee_code', $mappings->keys())
            ->where('sync_status', 'pending')
            ->orderBy('occurred_at')
            ->get(['id', 'employee_code']);

        $delay    = 0;
        $resolved = 0;

        foreach ($logs as $log) {
            $empId = $mappings[$log->employee_code] ?? null;
            if (!$empId) continue;

            AttendanceLog::where('id', $log->id)->update([
                'factorial_employee_id' => $empId,
                'sync_status'           => 'resolved',
            ]);

            SyncAttendanceToFactorial::dispatch($log->id)->delay(now()->addSeconds($delay));
            $delay += 2;
            $resolved++;
        }

        // Resueltos huérfanos (job perdido por worker caído) → re-despachar
        $stale = AttendanceLog::where('sync_status', 'resolved')
            ->whereNotNull('factorial_employee_id')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->orderBy('occurred_at')
            ->pluck('id');

        $requeued = 0;
        foreach ($stale as $id) {
            SyncAttendanceToFactorial::dispatch($id)->delay(now()->addSeconds($delay));
            $delay += 2;
            $requeued++;
        }

        $this->info("Resueltos y despachados: {$resolved} logs. Re-despachados huérfanos: {$requeued}.");
        return self::SUCCESS;
    }
}
