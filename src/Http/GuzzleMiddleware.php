<?php

declare(strict_types=1);

namespace PulsePHP\Http;

use Closure;
use GuzzleHttp\Promise\Create;
use PulsePHP\Pulse;

/**
 * Guzzle middleware that records every request as an outbound call.
 *
 *     $stack = HandlerStack::create();
 *     $stack->push(GuzzleMiddleware::create(), 'pulse');
 *     $client = new Client(['handler' => $stack]);
 *
 * Credentials and query strings are stripped before the URL is stored.
 */
final class GuzzleMiddleware
{
    /** @param Pulse|null $pulse Defaults to the initialized Pulse instance at call time. */
    public static function create(?Pulse $pulse = null): Closure
    {
        return static function (callable $handler) use ($pulse): Closure {
            return static function (object $request, array $options) use ($handler, $pulse): object {
                $startedAt = hrtime(true);
                $record = static function (int $status) use ($request, $startedAt, $pulse): void {
                    $instance = $pulse ?? (Pulse::isInitialized() ? Pulse::getInstance() : null);
                    $instance?->recordOutboundRequest(
                        (string) $request->getUri(),
                        (string) $request->getMethod(),
                        $status,
                        (hrtime(true) - $startedAt) / 1_000_000
                    );
                };

                return $handler($request, $options)->then(
                    static function (object $response) use ($record): object {
                        $record((int) $response->getStatusCode());

                        return $response;
                    },
                    static function (mixed $reason) use ($record): object {
                        $response = is_object($reason) && method_exists($reason, 'getResponse')
                            ? $reason->getResponse()
                            : null;
                        $record(is_object($response) ? (int) $response->getStatusCode() : 0);

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }
}
