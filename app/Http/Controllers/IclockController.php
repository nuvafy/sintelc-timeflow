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

        // Attendance photos
        if ($table === 'ATTPHOTO') {
            Log::info('ZKTeco ATTPHOTO EVENT', [
                'sn' => $sn,
                'body_preview' => mb_substr($body, 0, 500),
            ]);

            return response('OK', 200)
                ->header('Content-Type', 'text/plain');
        }

        // Operation logs
        if ($table === 'OPERLOG') {
            Log::info('ZKTeco OPERLOG EVENT', [
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

    public function registry(Request $request)
    {
        Log::info('ZKTeco REGISTRY', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body' => $request->getContent(),
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
        return "GET OPTION FROM: {$sn}
ATTLOGStamp=0
OPERLOGStamp=0
ATTPHOTOStamp=0
ErrorDelay=30
Delay=10
TransTimes=00:00;14:00
TransInterval=1
TransFlag=TransData AttLog\tOpLog\tAttPhoto\tEnrollUser\tChgUser\tEnrollFP\tChgFP\tUserPic
TimeZone=-6
Realtime=1
Encrypt=None
ServerVer=1.0.0
PushProtVer=2.4.2
PushOptionsFlag=1
PushOptions=FingerFunOn,FaceFunOn
SupportPing=1";
    }

    private function buildPushResponse(): string
    {
        return "ServerVersion=1.0.0
ServerName=Sintelc ADMS
PushVersion=2.4.2
ErrorDelay=60
RequestDelay=2
TransTimes=00:00;14:00
TransInterval=1
TransTables=User Transaction
Realtime=1
SessionID=demo-session-id
TimeoutSec=10";
    }

    private function buildRegistryCode(?string $sn): string
    {
        return substr(md5(($sn ?? 'unknown') . '|sintelc'), 0, 16);
    }
}
