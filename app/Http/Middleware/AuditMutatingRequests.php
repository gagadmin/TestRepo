<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutatingRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $routeName = $request->route()?->getName();

        if ($request->user()
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! in_array($routeName, ['login', 'logout'], true)) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'event' => strtolower($request->method()).'.'.$routeName,
                'auditable_type' => 'http_request',
                'auditable_id' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                ],
            ]);
        }

        return $response;
    }
}
