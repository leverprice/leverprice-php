<?php

declare(strict_types=1);

namespace Leverprice\Http;

/** Sends one HTTP request; pass your own as the `transport` option. */
interface Transport
{
    /**
     * Must enforce `$request->timeoutMs` as a hard limit and never follow redirects.
     *
     * @throws TransportException on network errors and timeouts
     */
    public function send(HttpRequest $request): HttpResponse;
}
