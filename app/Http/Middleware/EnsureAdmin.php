<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientSafeRedirect = $request->routeIs(
            'dashboard',
            'clients',
            'clients.show',
            'clients.records',
            'employees',
            'devices',
        );

        if ($request->user()?->isClient() && $request->isMethod('GET') && $clientSafeRedirect) {
            return redirect()->route('client.records');
        }

        if (!$request->user()?->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
