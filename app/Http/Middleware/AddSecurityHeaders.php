<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $development = app()->environment('local', 'testing');
        $viteOrigin = $development ? $this->viteOrigin() : null;
        $scriptSources = ["'self'"];
        $styleSources = ["'self'", "'unsafe-inline'"];
        $fontSources = ["'self'", 'data:'];
        $connectSources = ["'self'"];

        if ($development) {
            $scriptSources[] = "'unsafe-inline'";
            $scriptSources[] = "'unsafe-eval'";
        }

        if ($viteOrigin !== null) {
            $scriptSources[] = $viteOrigin;
            $styleSources[] = $viteOrigin;
            $fontSources[] = $viteOrigin;
            $connectSources[] = $viteOrigin;
            $connectSources[] = $this->webSocketOrigin($viteOrigin);
        }

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($scriptSources)),
            'style-src '.implode(' ', array_unique($styleSources)),
            'font-src '.implode(' ', array_unique($fontSources)),
            "img-src 'self' data: blob:",
            'connect-src '.implode(' ', array_unique($connectSources)),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function viteOrigin(): ?string
    {
        $url = trim((string) config('app.vite_dev_server_url'));
        $parts = parse_url($url);

        if ($url === ''
            || $parts === false
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    private function webSocketOrigin(string $origin): string
    {
        return str_starts_with($origin, 'https://')
            ? 'wss://'.substr($origin, 8)
            : 'ws://'.substr($origin, 7);
    }
}
