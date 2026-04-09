<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IclockController extends Controller
{
    public function ping(Request $request): Response
    {
        Log::info('ZKTeco PING', [
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
        ]);

        return $this->plainResponse('OK');
    }

    public function getRequest(Request $request): Response
    {
        Log::info('ZKTeco GETREQUEST', [
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
        ]);

        return $this->plainResponse('OK');
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

        return $this->plainResponse('OK');
    }

    public function registry(Request $request): Response
    {
        Log::info('ZKTeco REGISTRY', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
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
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body' => $request->getContent(),
        ]);

        return $this->plainResponse($this->buildPushResponse());
    }

    public function devicecmd(Request $request): Response
    {
        Log::info('ZKTeco DEVICECMD RESULT', [
            'method' => $request->method(),
            'query' => $request->query(),
            'cookies' => $request->cookies->all(),
            'body' => $request->getContent(),
        ]);

        return $this->plainResponse('OK');
    }

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

    private function splitRecords(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body))
        ));
    }

    private function plainResponse(string $body, int $status = 200)
    {
        // Evita que PHP agregue charset automáticamente
        ini_set('default_charset', '');

        http_response_code($status);
        header('Content-Type: text/plain'); // sin charset
        header('Content-Length: ' . strlen($body));
        header('Connection: close');

        echo $body;
        exit;
    }
}
