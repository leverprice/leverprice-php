<?php

declare(strict_types=1);

namespace Leverprice\Http;

/** A response from the Leverprice API. Header names are case-insensitive. */
final class HttpResponse
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            foreach ((array) $value as $v) {
                $this->headers[$key][] = $v;
            }
        }
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : implode(', ', $values);
    }

    /** @return array<string, list<string>> lower-case names */
    public function headers(): array
    {
        return $this->headers;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
