<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Collectors;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use PulsePHP\Pulse;
use WeakMap;

final class OutboundHttpCollector
{
    /** @var WeakMap<object, int> */
    private WeakMap $startedAt;

    public function __construct(private Pulse $pulse)
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

        $this->pulse->recordOutboundRequest(
            $request->url(),
            $request->method(),
            $statusCode,
            (hrtime(true) - $startedAt) / 1_000_000
        );
    }
}