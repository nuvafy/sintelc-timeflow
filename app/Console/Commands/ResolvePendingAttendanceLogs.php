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

        if ($mappings->isEmpty()) {
            $this->info('Sin mapeos disponibles.');
            return self::SUCCESS;
        }

        $logs = AttendanceLog::whereNull('factorial_employee_id')
            ->whereIn('employee_code', $mappings->keys())
            ->where('sync_status', 'pending')
            ->get(['id', 'employee_code']);

        if ($logs->isEmpty()) {
            $this->info('Sin logs pendientes para resolver.');
            return self::SUCCESS;
        }

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

        $this->info("Resueltos y despachados: {$resolved} logs.");
        return self::SUCCESS;
    }
}
