<?php

declare(strict_types=1);

namespace Leverprice\Tests\Support;

use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Leverprice;
use Leverprice\State\MemoryStore;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const SECRET_KEY = 'pp_sk_test_a1B2c3D4e5a1B2c3D4e5a1B2c3D4e5a1B2c3D4e5';
    protected const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';

    /** @var list<string> */
    protected array $errors = [];

    /** @return array<string, array<string, mixed>> */
    protected static function defaultGuards(): array
    {
        return [
            'pro' => ['currency' => 'usd', 'fallbackPrice' => 1900],
            'basic' => ['currency' => 'usd', 'fallbackPrice' => 1000],
            'euro' => ['currency' => 'eur', 'fallbackPrice' => 1200],
            'wild' => ['currency' => 'usd', 'fallbackPrice' => 2000],
            'bad' => ['currency' => 'usd', 'fallbackPrice' => 150],
            'ghost' => ['currency' => 'usd', 'fallbackPrice' => 4900],
            'geo' => ['currency' => 'usd', 'fallbackPrice' => 2900],
            'geox' => ['currency' => 'usd', 'fallbackPrice' => 2900],
            'badgeo' => ['currency' => 'usd', 'fallbackPrice' => 2900],
            'badgeox' => ['currency' => 'usd', 'fallbackPrice' => 2900],
        ];
    }

    /** @return list<string> */
    protected function reported(): array
    {
        return array_values(array_filter($this->errors, static fn(string $e): bool => !str_contains($e, 'malformed products')));
    }

    /**
     * @param (callable(HttpRequest): HttpResponse)|null $handler
     * @param array<string, mixed> $options
     * @return array{Leverprice, FakeTransport}
     */
    protected function vectorClient(FakeClock $clock, ?callable $handler = null, array $options = []): array
    {
        $vectors = Vectors::arrays();
        $transport = new FakeTransport($handler ?? self::vectorApi());
        $client = new Leverprice($options + [
            'secretKey' => self::str($vectors['secretKey']),
            'products' => self::defaultGuards(),
            'trustedRootKeys' => $vectors['rootKeys'],
            'transport' => $transport,
            'cache' => new MemoryStore(),
            'clock' => $clock,
            'registerShutdown' => false,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e->getMessage();
            },
        ]);

        return [$client, $transport];
    }

    /**
     * @param array<string, string> $configHeaders
     * @return \Closure(HttpRequest): HttpResponse
     */
    protected static function vectorApi(?string $envelope = null, array $configHeaders = []): \Closure
    {
        $body = $envelope ?? self::envelope(Vectors::objects()->config);

        return static function (HttpRequest $request) use ($body, $configHeaders): HttpResponse {
            if (str_ends_with($request->url, '/v1/config')) {
                return new HttpResponse(200, ['ETag' => '"v5"'] + $configHeaders, $body);
            }

            return new HttpResponse(202, [], '{"accepted":1,"dropped":0}');
        };
    }

    protected static function envelope(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected static function vectorNow(): int
    {
        return self::int(Vectors::arrays()['now']);
    }

    protected static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    protected static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    protected static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
