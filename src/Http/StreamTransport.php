<?php

declare(strict_types=1);

namespace Leverprice\Http;

/** The fallback transport when ext-curl is missing: PHP's http:// stream wrapper. */
final class StreamTransport implements Transport
{
    private const MAX_BODY_BYTES = 8388608;
    private const TIMER_SLACK_SECONDS = 0.05;

    public function send(HttpRequest $request): HttpResponse
    {
        $seconds = max(1, $request->timeoutMs) / 1000;
        $deadline = microtime(true) + $seconds;
        $lines = ['Connection: close'];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => $request->method,
            'header' => implode("\r\n", $lines),
            'content' => $request->body ?? '',
            'timeout' => $seconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.0,
        ]]);

        $error = null;
        set_error_handler(static function (int $errno, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $stream = fopen($request->url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $message = is_string($error) ? $error : 'request failed';
            $timedOut = microtime(true) >= $deadline - self::TIMER_SLACK_SECONDS || stripos($message, 'timed out') !== false;

            throw new TransportException($message, $timedOut);
        }

        try {
            $meta = stream_get_meta_data($stream);
            $head = self::head(is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : []);
            $length = $head->header('Content-Length');
            $expected = $length !== null && preg_match('/^\d{1,10}$/D', $length) === 1 ? (int) $length : null;
            if ($expected !== null && $expected > self::MAX_BODY_BYTES) {
                throw new TransportException('response body too large');
            }
            $body = '';
            while (($expected === null || strlen($body) < $expected) && !feof($stream)) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new TransportException('request timed out', true);
                }
                stream_set_timeout($stream, (int) $left, (int) (($left - floor($left)) * 1000000));
                $chunk = fread($stream, $expected === null ? 8192 : max(1, min(8192, $expected - strlen($body))));
                if ($chunk === false) {
                    break;
                }
                $body .= $chunk;
                if (stream_get_meta_data($stream)['timed_out']) {
                    throw new TransportException('request timed out', true);
                }
                if (strlen($body) > self::MAX_BODY_BYTES) {
                    throw new TransportException('response body too large');
                }
            }
        } finally {
            fclose($stream);
        }

        return new HttpResponse($head->status, $head->headers(), $body);
    }

    /** @param array<mixed> $lines */
    private static function head(array $lines): HttpResponse
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        return new HttpResponse($status, $headers);
    }
}
