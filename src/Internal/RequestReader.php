<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class RequestReader
{
    private const PREFETCH_HEADERS = ['sec-purpose', 'x-sec-purpose', 'purpose', 'x-purpose', 'x-moz'];
    private const NOT_A_COUNTRY = ['XX', 'ZZ', 'T1', 'A1', 'A2', 'AP', 'EU'];
    private const COUNTRY_INPUT_RE = '/\A[ \t\n\x0B\f\r]*([A-Za-z]{2})[ \t\n\x0B\f\r]*\z/';
    private const VISITOR_SET_PREFIX_RE = '/^(sec-|accept|content-|if-|x-forwarded-|proxy-)/';
    private const VISITOR_SET_HEADERS = [
        'authorization', 'cache-control', 'connection', 'cookie', 'dnt', 'forwarded', 'host', 'origin', 'pragma',
        'priority', 'purpose', 'range', 'referer', 'te', 'upgrade-insecure-requests', 'user-agent', 'via',
        'x-real-ip', 'x-requested-with',
    ];
    /** Read in this order. A forged one only makes the visitor new, as clearing cookies always could. */
    private const ADDRESS_HEADERS = [
        'cf-connecting-ip', 'true-client-ip', 'fastly-client-ip', 'fly-client-ip', 'x-real-ip', 'x-forwarded-for',
    ];
    private const IPV4_RE = '/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$/D';

    /**
     * What headers are read from: the context's request, else the superglobals.
     *
     * @param array<array-key, mixed> $context
     */
    public static function source(array $context): mixed
    {
        return $context['request'] ?? Superglobals::instance();
    }

    /** @param array<array-key, mixed> $context */
    public static function read(array $context, string $cookieName, ?string $countryHeader = null): RequestInfo
    {
        $source = self::source($context);
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
        return self::normalizeCountry(self::header(self::source($context), $header));
    }

    /** A header every browser or client sends itself: never a source for the visitor's country. */
    public static function visitorSetHeader(string $name): bool
    {
        $lower = strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        return preg_match(self::VISITOR_SET_PREFIX_RE, $lower) === 1 || in_array($lower, self::VISITOR_SET_HEADERS, true);
    }

    /** The visitor's address: the first address header that holds one, else the connection's. */
    public static function clientAddress(mixed $source): ?string
    {
        foreach (self::ADDRESS_HEADERS as $name) {
            $value = self::header($source, $name);
            if ($value === null) {
                continue;
            }
            $address = self::normalizeAddress(explode(',', $value, 2)[0]);
            if ($address !== null) {
                return $address;
            }
        }

        return self::normalizeAddress(self::connectionAddress($source));
    }

    /**
     * An address in one spelling: IPv4 in decimal, IPv6 cut to its /64 network as four lowercase hex
     * groups (phones change the rest every day). Accepts a port, brackets and a zone; null otherwise.
     */
    public static function normalizeAddress(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $s = trim($value, " \t");
        if (str_starts_with($s, '[')) {
            $end = strpos($s, ']');
            if ($end === false) {
                return null;
            }
            $rest = substr($s, $end + 1);
            if ($rest !== '' && preg_match('/^:[0-9]{1,5}$/D', $rest) !== 1) {
                return null;
            }
            $s = substr($s, 1, $end - 1);
        } elseif (preg_match('/^[0-9.]+:[0-9]{1,5}$/D', $s) === 1) {
            $s = substr($s, 0, (int) strpos($s, ':'));
        }
        $zone = strpos($s, '%');
        if ($zone !== false) {
            $s = substr($s, 0, $zone);
        }
        $v4 = self::ipv4($s);
        if ($v4 !== null) {
            return implode('.', $v4);
        }
        $v6 = self::ipv6($s);
        if ($v6 === null) {
            return null;
        }
        if (array_slice($v6, 0, 5) === [0, 0, 0, 0, 0] && $v6[5] === 0xFFFF) {
            return implode('.', [$v6[6] >> 8, $v6[6] & 255, $v6[7] >> 8, $v6[7] & 255]);
        }

        return implode(':', array_map('dechex', array_slice($v6, 0, 4)));
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

    /** @return list<int>|null */
    private static function ipv4(string $text): ?array
    {
        if (preg_match(self::IPV4_RE, $text, $m) !== 1) {
            return null;
        }
        $parts = array_map('intval', array_slice($m, 1));
        foreach ($parts as $n) {
            if ($n > 255) {
                return null;
            }
        }

        return $parts;
    }

    /** @return list<int>|null eight groups */
    private static function ipv6(string $text): ?array
    {
        $s = $text;
        if (str_contains($s, '.')) {
            $colon = strrpos($s, ':');
            $v4 = $colon === false ? null : self::ipv4(substr($s, $colon + 1));
            if ($colon === false || $v4 === null) {
                return null;
            }
            $s = substr($s, 0, $colon + 1) . dechex($v4[0] << 8 | $v4[1]) . ':' . dechex($v4[2] << 8 | $v4[3]);
        }
        $halves = explode('::', $s);
        if (count($halves) > 2) {
            return null;
        }
        $head = $halves[0] !== '' ? explode(':', $halves[0]) : [];
        $tail = count($halves) === 2 ? ($halves[1] !== '' ? explode(':', $halves[1]) : []) : null;
        $groups = [...$head, ...($tail ?? [])];
        foreach ($groups as $group) {
            if (preg_match('/^[0-9A-Fa-f]{1,4}$/D', $group) !== 1) {
                return null;
            }
        }
        $parse = static fn(string $group): int => (int) hexdec($group);
        if ($tail === null) {
            return count($groups) === 8 ? array_map($parse, $groups) : null;
        }
        if (count($groups) > 7) {
            return null;
        }

        return [...array_map($parse, $head), ...array_fill(0, 8 - count($groups), 0), ...array_map($parse, $tail)];
    }

    /** `REMOTE_ADDR` of `$_SERVER`, a `$_SERVER`-style array, PSR-7 server params or a Symfony/Laravel request. */
    private static function connectionAddress(mixed $source): mixed
    {
        try {
            if ($source instanceof Superglobals) {
                return $source->remoteAddress();
            }
            if (is_array($source)) {
                return $source['REMOTE_ADDR'] ?? null;
            }
            if (!is_object($source)) {
                return null;
            }
            if (method_exists($source, 'getServerParams')) {
                $params = $source->getServerParams();

                return is_array($params) ? $params['REMOTE_ADDR'] ?? null : null;
            }
            $server = $source->server ?? null;
            if (is_array($server)) {
                return $server['REMOTE_ADDR'] ?? null;
            }

            return is_object($server) && method_exists($server, 'get') ? $server->get('REMOTE_ADDR') : null;
        } catch (\Throwable) {
            return null;
        }
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
