<?php

declare(strict_types=1);

namespace Leverprice\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Sends requests through any PSR-18 client. Timeouts are the client's own settings. */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $psr = $this->requestFactory->createRequest($request->method, $request->url);
            foreach ($request->headers as $name => $value) {
                $psr = $psr->withHeader($name, $value);
            }
            if ($request->body !== null) {
                $psr = $psr->withBody($this->streamFactory->createStream($request->body));
            }
            $response = $this->client->sendRequest($psr);
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[(string) $name] = array_values(array_map('strval', $values));
            }

            return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), preg_match('/timed? ?out/i', $e->getMessage()) === 1, $e);
        }
    }
}
