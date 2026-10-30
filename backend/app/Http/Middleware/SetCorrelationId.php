<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID');
        if (! is_string($correlationId) || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $correlationId)) {
            $correlationId = (string) Str::uuid();
        }

        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
