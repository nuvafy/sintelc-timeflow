<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IclockController extends Controller
{
    public function ping(Request $request)
    {
        Log::info('ZKTeco PING', $request->query());

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function getRequest(Request $request)
    {
        Log::info('ZKTeco GETREQUEST', $request->query());

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
            'sn' => $sn,
            'table' => $table,
            'query' => $request->query(),
            'body' => $body,
        ]);

        // Attendance PUSH init
        if ($options === 'all') {
            Log::info('ZKTeco INIT REQUEST', [
                'sn' => $sn,
                'query' => $request->query(),
            ]);

            return response($this->buildInitResponse($sn), 200)
                ->header('Content-Type', 'text/plain');
        }

        // Attendance records
        if ($table === 'ATTLOG') {
            Log::info('ZKTeco ATTLOG EVENT', [
                'sn' => $sn,
                'body' => $body,
            ]);

            return response('OK: 1', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Security PUSH real-time events
        if ($table === 'rtlog') {
            Log::info('ZKTeco RTLOG EVENT', [
                'sn' => $sn,
                'body' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Device real-time status
        if ($table === 'rtstate') {
            Log::info('ZKTeco RTSTATE EVENT', [
                'sn' => $sn,
                'body' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Device/user/config uploads
        if ($table === 'options' || $table === 'tabledata') {
            Log::info('ZKTeco TABLEDATA EVENT', [
                'sn' => $sn,
                'table' => $table,
                'query' => $request->query(),
                'body' => $body,
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)
            ->header('Content-Type', 'text/plain');
    }

    private function buildInitResponse(?string $sn): string
    {
        return "GET OPTION FROM: {$sn}
ATTLOGStamp=0
OPERLOGStamp=0
ATTPHOTOStamp=0
ErrorDelay=30
Delay=10
TransTimes=00:00;14:00
TransInterval=1
TransFlag=TransData AttLog\tOpLog
TimeZone=-6
Realtime=1
Encrypt=None
SupportPing=1
PushProtVer=2.4.2";
    }
}
