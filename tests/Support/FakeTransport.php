<?php

declare(strict_types=1);

namespace Leverprice\Tests\Support;

use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var \Closure(HttpRequest): HttpResponse */
    private \Closure $handler;

    /** @param callable(HttpRequest): HttpResponse $handler */
    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return ($this->handler)($request);
    }

    /** @return list<HttpRequest> */
    public function configRequests(): array
    {
        return array_values(array_filter($this->requests, static fn(HttpRequest $r): bool => str_ends_with($r->url, '/v1/config')));
    }

    /** @return list<HttpRequest> */
    public function eventRequests(): array
    {
        return array_values(array_filter($this->requests, static fn(HttpRequest $r): bool => str_ends_with($r->url, '/v1/events')));
    }

    /** @return list<array<string, mixed>> */
    public function sentEvents(): array
    {
        $events = [];
        foreach ($this->eventRequests() as $request) {
            $body = json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !is_array($body['events'] ?? null)) {
                continue;
            }
            foreach ($body['events'] as $event) {
                if (is_array($event)) {
                    /** @var array<string, mixed> $event */
                    $events[] = $event;
                }
            }
        }

        return $events;
    }
}
