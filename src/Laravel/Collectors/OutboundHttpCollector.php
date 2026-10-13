<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Collectors;

use Closure;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use PulsePHP\Pulse;
use WeakMap;

final class OutboundHttpCollector
{
    /** @var WeakMap<object, int> */
    private WeakMap $startedAt;

    /** @param Pulse|Closure(): Pulse $pulse Resolved lazily when given a closure. */
    public function __construct(private Pulse|Closure $pulse)
    {
        $this->startedAt = new WeakMap();
    }

    public function requestSending(RequestSending $event): void
    {
        $this->startedAt[$event->request] = hrtime(true);
    }

    public function responseReceived(ResponseReceived $event): void
    {
        $this->record($event->request, $event->response->status());
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $this->record($event->request, 0);
    }

    private function record(object $request, int $statusCode): void
    {
        $startedAt = $this->startedAt[$request] ?? hrtime(true);
        unset($this->startedAt[$request]);

        $pulse = $this->pulse instanceof Closure ? ($this->pulse)() : $this->pulse;
        $pulse->recordOutboundRequest(
            $request->url(),
            $request->method(),
            $statusCode,
            (hrtime(true) - $startedAt) / 1_000_000
        );
    }
}
