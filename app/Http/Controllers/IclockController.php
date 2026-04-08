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
        Log::info('ZKTeco CDATA RAW', [
            'table' => $request->query('table'),
            'query' => $request->query(),
            'body' => $request->getContent(),
        ]);

        return response('OK', 200);
    }
}
