<?php

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation identifier and attaches it to the logs.
 *
 * The application already logs provider failures, detector failures, and
 * delivery failures, but nothing tied those lines to the request that caused
 * them, so diagnosing a reported error meant guessing from timestamps.
 * `CorrelationId` pushes the identifier into the log context, so every
 * subsequent line in the request carries it — including the ones Laravel's
 * exception handler writes — and returning it on the response lets a user
 * quote it when reporting a problem.
 *
 * An inbound identifier is honoured so a value assigned by a load balancer or
 * gateway survives into these logs, but only after validation: see
 * `CorrelationId::PATTERN`.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function __construct(private readonly CorrelationId $correlation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->correlation->use($request->headers->get(self::HEADER));

        // Also on the request, for code that has the request but not the
        // container binding to hand.
        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
