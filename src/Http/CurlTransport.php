<?php

declare(strict_types=1);

namespace Leverprice\Http;

/** The default transport when ext-curl is loaded. Reuses one connection per process. */
final class CurlTransport implements Transport
{
    private const MAX_BODY_BYTES = 8388608;

    private ?\CurlHandle $handle = null;

    public static function isSupported(): bool
    {
        return function_exists('curl_init');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $url = $request->url;
        $method = $request->method;
        if ($url === '' || $method === '') {
            throw new TransportException('missing URL or method');
        }
        $handle = $this->handle ?? curl_init();
        if ($handle === false) {
            throw new TransportException('curl_init() failed');
        }
        $this->handle = $handle;
        curl_reset($handle);

        $headers = [];
        $body = '';
        $tooLarge = false;
        $lines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $timeout = max(1, $request->timeoutMs);
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_CONNECTTIMEOUT_MS => $timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options);

        if (curl_exec($handle) === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            if ($tooLarge) {
                throw new TransportException('response body too large');
            }

            throw new TransportException($error !== '' ? $error : 'curl error ' . $errno, $errno === CURLE_OPERATION_TIMEDOUT);
        }

        /** @var array<string, list<string>> $headers */
        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
