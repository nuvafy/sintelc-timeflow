<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IclockController extends Controller
{
    public function ping(Request $request): Response
    {
        $sn = $request->query('SN');

        Log::info('ZKTeco PING', ['query' => $request->query()]);

        if ($sn) {
            BiometricSource::updateOrCreate(
                ['serial_number' => $sn],
                ['last_ping_at' => now()]
            );
        }

        return $this->plainResponse('OK');
    }

    public function getRequest(Request $request): Response
    {
        $sn = $request->query('SN');

        Log::info('ZKTeco GETREQUEST', ['query' => $request->query()]);

        if (!$sn) {
            return $this->plainResponse('OK');
        }

        $source = BiometricSource::updateOrCreate(
            ['serial_number' => $sn],
            ['last_ping_at' => now()]
        );

        if (!$source->client_id) {
            return $this->plainResponse('OK');
        }

        $command = DeviceCommand::where('biometric_source_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('command_seq')
            ->first();

        if (!$command) {
            return $this->plainResponse($this->buildGetRequestResponse());
        }

        $command->update(['status' => 'sent', 'sent_at' => now()]);

        $line = $this->buildGetRequestResponse("C:{$command->command_seq}:{$command->payload}");

        return $this->plainResponse($line);
    }

    public function cdata(Request $request)
    {
        $sn      = $request->query('SN');
        $table   = $request->query('table');
        $options = $request->query('options');

        Log::info('ZKTeco CDATA', [
            'sn'    => $sn,
            'table' => $table,
            'body'  => mb_substr($request->getContent(), 0, 500),
        ]);

        if ($options === 'all') {
            return $this->plainResponse($this->buildInitResponse($sn));
        }

        if ($table === 'options' && $sn) {
            $body = $request->getContent();
            preg_match('/PushVersion=([^\r\n,]+)/i', $body, $m);
            if (!empty($m[1])) $m[1] = trim($m[1]);
            if (!empty($m[1])) {
                BiometricSource::where('serial_number', $sn)
                    ->update(['push_version' => $m[1]]);
            }
        }

        if ($table === 'ATTLOG') {
            return $this->handleAttlog($request, $sn);
        }

        if ($table === 'rtlog') {
            return $this->handleRtlog($request, $sn);
        }

        if ($table === 'USERINFO' || $table === 'user') {
            return $this->handleUserInfo($request, $sn, $table);
        }

        return $this->plainResponse('OK');
    }

    public function registry(Request $request): Response
    {
        Log::info('ZKTeco REGISTRY', [
            'method'       => $request->method(),
            'query'        => $request->query(),
            'body_preview' => mb_substr($request->getContent(), 0, 1000),
        ]);

        return $this->plainResponse(
            "RegistryCode={$this->buildRegistryCode($request->query('SN'))}"
        );
    }

    public function push(Request $request): Response
    {
        Log::info('ZKTeco PUSH CONFIG REQUEST', [
            'method' => $request->method(),
            'query'  => $request->query(),
            'body'   => $request->getContent(),
        ]);

        return $this->plainResponse($this->buildPushResponse());
    }

    public function devicecmd(Request $request): Response
    {
        $sn   = $request->query('SN');
        $body = $request->getContent();

        Log::info('ZKTeco DEVICECMD RESULT', [
            'method' => $request->method(),
            'query'  => $request->query(),
            'body'   => $body,
        ]);

        // Formato de respuesta: ID=123\nReturn=0\nCMD=DATA UPDATE USERINFO...
        if ($sn) {
            $source = BiometricSource::where('serial_number', $sn)->first();

            if ($source) {
                // Extraer el ID del comando de la respuesta del equipo
                preg_match('/ID=(\d+)/i', $body, $matches);
                $commandSeq = isset($matches[1]) ? (int) $matches[1] : null;

                if ($commandSeq !== null) {
                    preg_match('/Return=(-?\d+)/i', $body, $retMatches);
                    $returnCode = isset($retMatches[1]) ? (int) $retMatches[1] : null;
                    $status     = ($returnCode === 0) ? 'acknowledged' : 'failed';

                    DeviceCommand::where('biometric_source_id', $source->id)
                        ->where('command_seq', $commandSeq)
                        ->where('status', 'sent')
                        ->update([
                            'status'           => $status,
                            'acknowledged_at'  => now(),
                            'device_response'  => mb_substr($body, 0, 1000),
                        ]);
                }
            }
        }

        return $this->plainResponse('OK');
    }

    // ─── Private: Handlers ───────────────────────────────────────────

    private function handleUserInfo(Request $request, ?string $sn, string $table = 'USERINFO'): Response
    {
        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        $lines = $this->splitRecords($request->getContent());
        $users = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $fields = [];
            foreach (explode("\t", $line) as $part) {
                [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
                $fields[trim($key)] = trim($val);
            }

            // Attendance PUSH Protocol: PIN=  /  Security PUSH Protocol: Pin=
            $pin = $fields['PIN'] ?? $fields['Pin'] ?? null;
            if (empty($pin)) continue;

            $users[] = [
                'pin'       => $pin,
                'name'      => $fields['Name'] ?? '',
                // Attendance PUSH: Card= / Security PUSH: CardNo=
                'card'      => $fields['Card'] ?? $fields['CardNo'] ?? '',
                // Attendance PUSH: Role= / Security PUSH: Privilege=
                'privilege' => $fields['Role'] ?? $fields['Privilege'] ?? '0',
                'protocol'  => $table === 'user' ? 'security' : 'attendance',
            ];
        }

        $source->update([
            'device_users'            => $users,
            'device_users_fetched_at' => now(),
        ]);

        Log::info('ZKTeco USERINFO recibido', ['sn' => $sn, 'table' => $table, 'count' => count($users)]);

        return $this->plainResponse('OK');
    }

    private function handleAttlog(Request $request, ?string $sn): Response
    {
        $source = BiometricSource::where('serial_number', $sn)
            ->where('status', 'active')
            ->first();

        if (!$source) {
            Log::warning('ZKTeco ATTLOG: dispositivo no registrado o inactivo', ['sn' => $sn]);
            return $this->plainResponse('OK: 0');
        }

        $lines = $this->splitRecords($request->getContent());
        $now   = now();

        // ── Pre-cargar todo antes del loop ───────────────────────────
        $mappings = \App\Models\BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $factorialCompanyId = \App\Models\FactorialConnection::where('client_id', $source->client_id)
            ->whereNotNull('factorial_company_id')
            ->value('factorial_company_id');

        $attendanceConfig = \App\Models\ClientAttendanceConfig::where('client_id', $source->client_id)->first();

        if (!$attendanceConfig) {
            Log::warning('ZKTeco ATTLOG: cliente sin configuración de asistencia, registros ignorados', [
                'client_id' => $source->client_id,
                'sn'        => $sn,
            ]);
            return $this->plainResponse('OK: 0');
        }

        // ── Pre-cargar claves únicas ya existentes (evita N+1 de exists()) ──
        $existingKeys = AttendanceLog::where('biometric_source_id', $source->id)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->pluck(\Illuminate\Support\Facades\DB::raw("CONCAT(employee_code, '|', occurred_at)"))
            ->flip();

        // ── Parsear líneas ───────────────────────────────────────────
        $records = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $pin       = $parts[0] ?? null;
            $timestamp = $parts[1] ?? null;
            $status    = $parts[2] ?? null;
            $verify    = $parts[3] ?? null;
            $workcode  = $parts[4] ?? null;

            if (!$pin || !$timestamp) continue;

            try {
                $occurredAt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestamp, config('app.timezone'));
            } catch (\Exception $e) {
                Log::warning('ZKTeco ATTLOG: timestamp inválido', ['raw' => $timestamp]);
                continue;
            }

            $key = $pin . '|' . $occurredAt->format('Y-m-d H:i:s');
            if (isset($existingKeys[$key])) continue;

            $employeeId = $mappings[$pin] ?? null;

            $records[] = [
                'client_id'             => $source->client_id,
                'biometric_source_id'   => $source->id,
                'factorial_employee_id' => $employeeId,
                'employee_code'         => $pin,
                'check_type'            => $attendanceConfig->resolveCheckType($status) ?? 'unknown',
                'occurred_at'           => $occurredAt,
                'raw_payload'           => json_encode(compact('pin', 'timestamp', 'status', 'verify', 'workcode')),
                'sync_status'           => $employeeId ? 'resolved' : 'pending',
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        if (!empty($records)) {
            AttendanceLog::insert($records);

            // ── Despachar jobs para records resueltos ────────────────────
            $resolvedCodes = array_column(
                array_filter($records, fn($r) => $r['sync_status'] === 'resolved'),
                'employee_code'
            );

            if (!empty($resolvedCodes)) {
                // Recuperar los IDs recién insertados, ordenados cronológicamente.
                // Al ordenar por occurred_at ASC garantizamos que los registros
                // acumulados offline se envían a Factorial de más antiguo a más
                // reciente: check_out de ayer antes que check_in de hoy.
                $insertedIds = AttendanceLog::where('biometric_source_id', $source->id)
                    ->whereIn('employee_code', $resolvedCodes)
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->orderBy('occurred_at')
                    ->pluck('id');

                $delay = 0;
                foreach ($insertedIds as $logId) {
                    SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
                    $delay += 2;
                }
            }
        }

        Log::info('ZKTeco ATTLOG procesado', ['sn' => $sn, 'count' => count($records)]);

        return $this->plainResponse('OK: ' . count($records));
    }

    private function handleRtlog(Request $request, ?string $sn): Response
    {
        $source = BiometricSource::where('serial_number', $sn)
            ->where('status', 'active')
            ->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        // rtlog format: time=Y-m-d H:i:s\tpin=X\tinoutstatus=X\t...
        $fields = [];
        foreach (explode("\t", trim($request->getContent())) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $fields[trim($key)] = trim($val);
        }

        $pin    = $fields['pin'] ?? null;
        $time   = $fields['time'] ?? null;
        $status = $fields['inoutstatus'] ?? null;

        // pin=0 son eventos de sistema (puerta, alarma), no marcajes de empleados
        if (!$pin || $pin === '0' || !$time) {
            return $this->plainResponse('OK');
        }

        try {
            $occurredAt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $time, config('app.timezone'));
        } catch (\Exception $e) {
            return $this->plainResponse('OK');
        }

        $attendanceConfig = \App\Models\ClientAttendanceConfig::where('client_id', $source->client_id)->first();

        if (!$attendanceConfig) {
            return $this->plainResponse('OK');
        }

        $key = $pin . '|' . $occurredAt->format('Y-m-d H:i:s');
        $exists = AttendanceLog::where('biometric_source_id', $source->id)
            ->whereRaw("CONCAT(employee_code, '|', occurred_at) = ?", [$key])
            ->exists();

        if ($exists) {
            return $this->plainResponse('OK');
        }

        $employeeId = \App\Models\BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->where('external_employee_code', $pin)
            ->value('factorial_employee_id');

        $log = AttendanceLog::create([
            'client_id'             => $source->client_id,
            'biometric_source_id'   => $source->id,
            'factorial_employee_id' => $employeeId,
            'employee_code'         => $pin,
            'check_type'            => $attendanceConfig->resolveCheckType($status) ?? 'unknown',
            'occurred_at'           => $occurredAt,
            'raw_payload'           => json_encode($fields),
            'sync_status'           => $employeeId ? 'resolved' : 'pending',
        ]);

        if ($employeeId) {
            SyncAttendanceToFactorial::dispatch($log->id);
        }

        Log::info('ZKTeco rtlog procesado', ['sn' => $sn, 'pin' => $pin, 'time' => $time]);

        return $this->plainResponse('OK');
    }

    // ─── Private: Builders ───────────────────────────────────────────

    private function buildGetRequestResponse(string $command = 'OK'): string
    {
        return $command;
    }

    private function buildInitResponse(?string $sn): string
    {
        return "GET OPTION FROM: {$sn}\n"
            . "Stamp=0\n"
            . "TimeZone=-6\n"
            . "TransFlag=111111111111\n"
            . "PushProtVer=2.4.1\n";
    }

    private function buildPushResponse(): string
    {
        return implode("\n", [
            'ServerVersion=1.0.0',
            'ServerName=Sintelc ADMS',
            'PushVersion=2.4.2',
            'ErrorDelay=60',
            'RequestDelay=2',
            'TransTimes=00:00;14:00',
            'TransInterval=1',
            'TransTables=Transaction',  // solo asistencia; usuarios solo bajo petición explícita (DATA QUERY USERINFO)
            'Realtime=1',
            'SessionID=demo-session-id',
            'TimeoutSec=10',
        ]) . "\n";
    }

    private function buildRegistryCode(?string $sn): string
    {
        return substr(md5(($sn ?? 'unknown') . '|sintelc'), 0, 16);
    }

    // ─── Private: Utilities ──────────────────────────────────────────

    private function splitRecords(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body))
        ));
    }

    private function plainResponse(string $body, int $status = 200): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Length', strlen($body))
            ->header('Connection', 'close');
    }
}
