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
                ['last_ping_at' => now(), 'vendor' => 'ZKTeco']
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

        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        $command = DeviceCommand::where('biometric_source_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('command_seq')
            ->first();

        if (!$command) {
            return $this->plainResponse('OK');
        }

        $command->update(['status' => 'sent', 'sent_at' => now()]);

        $line = "C:{$command->command_seq}:{$command->payload}";

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

        if ($table === 'ATTLOG') {
            return $this->handleAttlog($request, $sn);
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

    private function handleAttlog(Request $request, ?string $sn): void
    {
        $source = BiometricSource::where('serial_number', $sn)
            ->where('status', 'active')
            ->first();

        if (!$source) {
            Log::warning('ZKTeco ATTLOG: dispositivo no registrado o inactivo', ['sn' => $sn]);
            $this->plainResponse('OK: 0');
        }

        $lines   = $this->splitRecords($request->getContent());
        $records = [];
        $now     = now();

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

            $exists = AttendanceLog::where('biometric_source_id', $source->id)
                ->where('employee_code', $pin)
                ->where('occurred_at', $occurredAt)
                ->exists();

            if ($exists) continue;

            $records[] = [
                'client_id'           => $source->client_id,
                'biometric_source_id' => $source->id,
                'employee_code'       => $pin,
                'check_type'          => $this->resolveCheckType($status),
                'occurred_at'         => $occurredAt,
                'raw_payload'         => json_encode([
                    'pin'      => $pin,
                    'time'     => $timestamp,
                    'status'   => $status,
                    'verify'   => $verify,
                    'workcode' => $workcode,
                ]),
                'sync_status' => 'pending',
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (!empty($records)) {
            AttendanceLog::insert($records);

            $inserted = AttendanceLog::where('biometric_source_id', $source->id)
                ->where('sync_status', 'pending')
                ->whereIn('occurred_at', array_column($records, 'occurred_at'))
                ->get();

            foreach ($inserted as $attendanceLog) {
                SyncAttendanceToFactorial::dispatch($attendanceLog->id);
            }
        }

        Log::info('ZKTeco ATTLOG procesado', ['sn' => $sn, 'count' => count($records)]);

        $this->plainResponse('OK: ' . count($records));
    }

    // ─── Private: Builders ───────────────────────────────────────────

    private function buildInitResponse(?string $sn): string
    {
        return "GET OPTION FROM: {$sn}\n"
            . "Stamp=0\n"
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
            'TransTables=User Transaction',
            'Realtime=1',
            'SessionID=demo-session-id',
            'TimeoutSec=10',
        ]) . "\n";
    }

    private function buildRegistryCode(?string $sn): string
    {
        return substr(md5(($sn ?? 'unknown') . '|sintelc'), 0, 16);
    }

    private function resolveCheckType(?string $status): string
    {
        return match ($status) {
            '0'     => 'check_in',
            '1'     => 'check_out',
            '4'     => 'break_out',
            '5'     => 'break_in',
            default => 'unknown',
        };
    }

    // ─── Private: Utilities ──────────────────────────────────────────

    private function splitRecords(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body))
        ));
    }

    private function plainResponse(string $body, int $status = 200): never
    {
        ini_set('default_charset', '');

        http_response_code($status);
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');

        echo $body;
        exit;
    }
}
