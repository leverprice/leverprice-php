<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class RequestReader
{
    private const PREFETCH_HEADERS = ['sec-purpose', 'x-sec-purpose', 'purpose', 'x-purpose', 'x-moz'];
    private const NOT_A_COUNTRY = ['XX', 'ZZ', 'T1', 'A1', 'A2', 'AP', 'EU'];
    private const COUNTRY_INPUT_RE = '/\A[ \t\n\x0B\f\r]*([A-Za-z]{2})[ \t\n\x0B\f\r]*\z/';

    /** @param array<array-key, mixed> $context */
    public static function read(array $context, string $cookieName, ?string $countryHeader = null): RequestInfo
    {
        $request = $context['request'] ?? null;
        $source = $request ?? Superglobals::instance();
        $explicit = isset($context['cookie']) && is_string($context['cookie']) ? $context['cookie'] : null;
        $cookieInput = $explicit ?? self::header($source, 'cookie');
        $pinned = false;
        if ($explicit !== null && !str_contains($explicit, '=')) {
            $bare = Util::trim($explicit);
            $assignmentCookies = $bare === '' ? [] : [$bare];
        } else {
            $assignmentCookies = self::cookieValues($cookieInput, $cookieName);
            $pinned = self::cookieValues($cookieInput, $cookieName . '_f') !== [];
        }
        $userAgent = isset($context['userAgent']) && is_string($context['userAgent'])
            ? $context['userAgent']
            : self::header($source, 'user-agent');
        $country = isset($context['country']) && is_string($context['country'])
            ? $context['country']
            : ($countryHeader === null ? null : self::header($source, $countryHeader));

        return new RequestInfo(
            $cookieInput,
            $assignmentCookies,
            $pinned,
            $userAgent,
            self::isPrefetch($source),
            self::normalizeCountry($country),
        );
    }

    /** @param array<array-key, mixed> $context */
    public static function headerCountry(array $context, string $header): ?string
    {
        return self::normalizeCountry(self::header($context['request'] ?? Superglobals::instance(), $header));
    }

    public static function normalizeCountry(mixed $value): ?string
    {
        if (!is_string($value) || preg_match(self::COUNTRY_INPUT_RE, $value, $match) !== 1) {
            return null;
        }
        $code = strtr($match[1], 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');

        return in_array($code, self::NOT_A_COUNTRY, true) ? null : $code;
    }

    public static function header(mixed $request, string $name): ?string
    {
        $lower = strtolower($name);
        try {
            $values = match (true) {
                $request instanceof Superglobals => $request->header($lower),
                is_array($request) => self::fromArray($request, $lower),
                is_object($request) => self::fromObject($request, $lower),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
        if (is_string($values)) {
            return $values;
        }
        if (!is_array($values)) {
            return null;
        }
        $parts = array_values(array_filter($values, 'is_string'));

        return $parts === [] ? null : implode($lower === 'cookie' ? '; ' : ', ', $parts);
    }

    /** @return list<string> */
    public static function cookieValues(?string $header, string $name): array
    {
        if ($header === null || $header === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;,]/', $header) ?: [] as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0 || Util::trim(substr($part, 0, $eq)) !== $name) {
                continue;
            }
            $value = Util::trim(substr($part, $eq + 1));
            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            if (str_contains($value, '%')) {
                $value = self::percentDecode($value);
            }
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    public static function isPrefetch(mixed $request): bool
    {
        if ($request === null) {
            return false;
        }
        foreach (self::PREFETCH_HEADERS as $name) {
            $value = self::header($request, $name);
            if ($value !== null && preg_match('/prefetch|prerender|preview/i', $value) === 1) {
                return true;
            }
        }

        return self::header($request, 'next-router-prefetch') === '1';
    }

    private static function percentDecode(string $value): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            return $value;
        }
        $decoded = rawurldecode($value);

        return Util::isUtf8($decoded) ? $decoded : $value;
    }

    /** @param array<array-key, mixed> $headers */
    private static function fromArray(array $headers, string $lower): mixed
    {
        if (array_key_exists($lower, $headers)) {
            return $headers[$lower];
        }
        foreach ($headers as $key => $value) {
            if (is_string($key) && strtolower($key) === $lower) {
                return $value;
            }
        }
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $lower));

        return $headers[$server] ?? null;
    }

    private static function fromObject(object $request, string $lower): mixed
    {
        if (method_exists($request, 'getHeader')) {
            return $request->getHeader($lower);
        }
        $headers = $request->headers ?? null;
        if (is_array($headers)) {
            return self::fromArray($headers, $lower);
        }
        if (is_object($headers)) {
            if (method_exists($headers, 'all')) {
                $all = $headers->all($lower);
                if (is_array($all) && array_is_list($all) && $all !== [] && is_string($all[0])) {
                    return $all;
                }
            }
            if (method_exists($headers, 'get')) {
                return $headers->get($lower);
            }
        }
        if (method_exists($request, 'header')) {
            return $request->header($lower);
        }

        return null;
    }
}
