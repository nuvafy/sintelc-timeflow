<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IclockController extends Controller
{
    public function ping(Request $request)
    {
        Log::info('ZKTeco PING', [
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
        ]);

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function getRequest(Request $request)
    {
        Log::info('ZKTeco GETREQUEST', [
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
        ]);

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function cdata(Request $request)
    {
        $sn = $request->query('SN');
        $table = $request->query('table');
        $options = $request->query('options');
        $body = $request->getContent();

        Log::info('ZKTeco CDATA RAW', [
            'method' => $request->method(),
            'sn' => $sn,
            'table' => $table,
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body_preview' => mb_substr($body, 0, 500),
        ]);

        // Attendance PUSH init
        if ($options === 'all') {
            Log::info('ZKTeco INIT REQUEST', [
                'sn' => $sn,
                'query' => $request->query(),
            ]);

            return response($this->buildInitResponse($sn), 200)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Length', strlen($this->buildInitResponse($sn)));
        }

        // Attendance records
        if ($table === 'ATTLOG') {
            $records = $this->splitRecords($body);

            foreach ($records as $line) {
                $parts = preg_split('/\t+/', trim($line));

                Log::info('ZKTeco ATTLOG RECORD', [
                    'sn' => $sn,
                    'pin' => $parts[0] ?? null,
                    'time' => $parts[1] ?? null,
                    'status' => $parts[2] ?? null,
                    'verify' => $parts[3] ?? null,
                    'workcode' => $parts[4] ?? null,
                    'raw' => $line,
                ]);
            }

            return response('OK: ' . count($records), 200)
                ->header('Content-Type', 'text/plain');
        }

        // Operation / user / biometric uploads
        if ($table === 'OPERLOG') {
            $records = $this->splitRecords($body);

            foreach ($records as $line) {
                if (str_starts_with($line, 'USER ')) {
                    Log::info('ZKTeco USER RECORD', [
                        'sn' => $sn,
                        'raw' => $line,
                    ]);
                } elseif (str_starts_with($line, 'OPLOG ')) {
                    Log::info('ZKTeco OPLOG RECORD', [
                        'sn' => $sn,
                        'raw' => $line,
                    ]);
                } elseif (str_starts_with($line, 'FP ')) {
                    Log::info('ZKTeco FP RECORD', [
                        'sn' => $sn,
                        'raw' => $line,
                    ]);
                } elseif (str_starts_with($line, 'FACE ')) {
                    Log::info('ZKTeco FACE RECORD', [
                        'sn' => $sn,
                        'raw' => $line,
                    ]);
                } elseif (str_starts_with($line, 'BIOPHOTO ')) {
                    Log::info('ZKTeco BIOPHOTO RECORD', [
                        'sn' => $sn,
                        'raw_preview' => mb_substr($line, 0, 300),
                    ]);
                } else {
                    Log::info('ZKTeco OPERLOG UNKNOWN RECORD', [
                        'sn' => $sn,
                        'raw' => $line,
                    ]);
                }
            }

            return response('OK: ' . count($records), 200)
                ->header('Content-Type', 'text/plain');
        }

        // Attendance photos
        if ($table === 'ATTPHOTO') {
            Log::info('ZKTeco ATTPHOTO EVENT', [
                'sn' => $sn,
                'body_length' => strlen($body),
                'has_null_byte' => str_contains($body, "\0"),
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Security PUSH real-time events
        if ($table === 'rtlog') {
            Log::info('ZKTeco RTLOG EVENT', [
                'sn' => $sn,
                'raw' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Security PUSH real-time status
        if ($table === 'rtstate') {
            Log::info('ZKTeco RTSTATE EVENT', [
                'sn' => $sn,
                'raw' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Device/user/config uploads
        if ($table === 'options' || $table === 'tabledata') {
            Log::info('ZKTeco TABLEDATA EVENT', [
                'sn' => $sn,
                'table' => $table,
                'raw' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function registry(Request $request)
    {
        Log::info('ZKTeco REGISTRY', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body_preview' => mb_substr($request->getContent(), 0, 1000),
        ]);

        return response("RegistryCode={$this->buildRegistryCode($request->query('SN'))}", 200)
            ->header('Content-Type', 'text/plain');
    }

    public function push(Request $request)
    {
        Log::info('ZKTeco PUSH CONFIG REQUEST', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body' => $request->getContent(),
        ]);

        return response($this->buildPushResponse(), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function devicecmd(Request $request)
    {
        Log::info('ZKTeco DEVICECMD RESULT', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body' => $request->getContent(),
        ]);

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    private function buildInitResponse(?string $sn): string
    {
        return "GET OPTION FROM: {$sn}\n" .
            "Stamp=0\n" .
            "TransFlag=111111111111\n" .
            "PushProtVer=2.4.1\n";
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
        ]);
    }

    private function buildRegistryCode(?string $sn): string
    {
        return substr(md5(($sn ?? 'unknown') . '|sintelc'), 0, 16);
    }

    private function splitRecords(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body))
        ));
    }
}
