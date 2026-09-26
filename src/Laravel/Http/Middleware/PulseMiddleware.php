<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PulsePHP\Pulse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PulseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('pulse.enabled', true)) {
            return $next($request);
        }

        $collectRequests = (bool) config('pulse.collect_requests', true);
        $collectExceptions = (bool) config('pulse.collect_exceptions', true);
        if (!$collectRequests && !$collectExceptions) {
            return $next($request);
        }

        $pulse = app(Pulse::class);
        $startedAt = hrtime(true);
        $memoryAtStart = memory_get_usage(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($collectExceptions) {
                $pulse->recordException($exception);
            }
            if ($collectRequests) {
                $this->recordRequest($pulse, $request, 500, $startedAt, $memoryAtStart);
            }

            throw $exception;
        }

        if ($collectRequests) {
            $this->recordRequest(
                $pulse,
                $request,
                $response->getStatusCode(),
                $startedAt,
                $memoryAtStart
            );
        }

        return $response;
    }

    private function recordRequest(
        Pulse $pulse,
        Request $request,
        int $status,
        int $startedAt,
        int $memoryAtStart
    ): void {
        $pulse->recordRequest(
            $request->fullUrl(),
            $request->method(),
            $status,
            (hrtime(true) - $startedAt) / 1_000_000,
            max(0, memory_get_peak_usage(true) - $memoryAtStart),
            $request->ip()
        );
    }
}