<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IclockController extends Controller
{
    public function ping(Request $request)
    {
        Log::info('ZKTeco PING', $request->all());

        return response('OK', 200);
    }

    public function getRequest(Request $request)
    {
        Log::info('ZKTeco GETREQUEST', $request->all());

        return response('OK', 200);
    }

    public function cdata(Request $request)
    {
        $sn = $request->query('SN');
        $options = $request->query('options');

        Log::info('ZKTeco CDATA RAW', [
            'table' => $request->query('table'),
            'query' => $request->query(),
            'body' => $request->getContent(),
        ]);

        // 👉 ESTE ES EL PUNTO CLAVE
        if ($options === 'all') {
            return response($this->buildInitResponse($sn), 200)
                ->header('Content-Type', 'text/plain');
        }

        // 👉 Cuando empiecen a llegar ATTLOG
        if ($request->query('table') === 'ATTLOG') {
            // aquí después parseamos y guardamos
            return response('OK: 1', 200);
        }

        return response('OK', 200);
    }

    private function buildInitResponse($sn)
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
