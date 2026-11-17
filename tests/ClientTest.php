<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Http\TransportException;
use Leverprice\Internal\Ed25519;
use Leverprice\Internal\Util;
use Leverprice\Leverprice;
use Leverprice\Quote;
use Leverprice\State\MemoryStore;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\FakeTransport;
use Leverprice\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    private const PRODUCTS = ['pro' => ['currency' => 'USD', 'fallbackPrice' => 1900, 'minPrice' => 1500, 'maxPrice' => 3900]];
    private const BROWSER = ['user-agent' => self::UA];

    protected function tearDown(): void
    {
        putenv('LEVERPRICE_DISABLED');
        unset($_ENV['LEVERPRICE_DISABLED'], $_SERVER['LEVERPRICE_DISABLED']);
        Ed25519::$mode = getenv('LEVERPRICE_TEST_SODIUM_COMPAT') === '1' ? Ed25519::COMPAT : null;
    }

    /**
     * @param (callable(HttpRequest): HttpResponse)|null $handler
     * @param array<string, mixed> $options
     * @return array{Leverprice, FakeTransport}
     */
    private function client(?callable $handler = null, array $options = [], ?FakeClock $clock = null): array
    {
        $transport = new FakeTransport($handler ?? static fn(HttpRequest $r): HttpResponse => str_ends_with($r->url, '/v1/config')
            ? new HttpResponse(503, [], '{}')
            : new HttpResponse(202, [], '{}'));
        $client = new Leverprice($options + [
            'secretKey' => self::SECRET_KEY,
            'products' => self::PRODUCTS,
            'transport' => $transport,
            'cache' => new MemoryStore(),
            'clock' => $clock ?? new FakeClock(1800000000000),
            'registerShutdown' => false,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e->getMessage();
            },
        ]);

        return [$client, $transport];
    }

    /** @return iterable<string, array{string}> */
    public static function disabledValues(): iterable
    {
        foreach (['1', 'true', 'TRUE', ' true '] as $value) {
            yield json_encode($value, JSON_THROW_ON_ERROR) => [$value];
        }
    }

    #[DataProvider('disabledValues')]
    public function testEnvironmentKillSwitch(string $value): void
    {
        putenv('LEVERPRICE_DISABLED=' . $value);
        [$client, $transport] = $this->client();

        $quote = $client->getPrice('pro', ['request' => self::BROWSER]);
        $client->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1900, 'currency' => 'usd']);
        $client->preload();
        $client->refresh();
        $client->flush();
        $client->terminate();

        self::assertSame([1900, true, Quote::REASON_DISABLED, [], null], [$quote->price, $quote->isFallback, $quote->reason, $quote->setCookie, $quote->visitorId]);
        self::assertSame([], $transport->requests);
        self::assertSame([], $this->errors);
    }

    public function testKillSwitchIsReadFromEnvSuperglobalsOnEveryCall(): void
    {
        [$client] = $this->client();
        $_ENV['LEVERPRICE_DISABLED'] = '1';
        self::assertSame(Quote::REASON_DISABLED, $client->getPrice('pro')->reason);
        unset($_ENV['LEVERPRICE_DISABLED']);
        $_SERVER['LEVERPRICE_DISABLED'] = 'true';
        self::assertSame(Quote::REASON_DISABLED, $client->getPrice('pro')->reason);
        $_SERVER['LEVERPRICE_DISABLED'] = '0';
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro')->reason);
    }

    public function testKillSwitchOption(): void
    {
        [$client, $transport] = $this->client(null, ['disabled' => true, 'secretKey' => 'malformed']);
        self::assertSame(Quote::REASON_DISABLED, $client->getPrice('pro')->reason);
        self::assertSame([], $transport->requests);
        self::assertSame([], $this->errors);
    }

    public function testMissingOrMalformedSecretKey(): void
    {
        foreach ([null, '', '   ', 'sk_live_123', 'pp_sk_live_' . str_repeat('x', 39)] as $key) {
            [$client, $transport] = $this->client(null, ['secretKey' => $key]);
            $quote = $client->getPrice('pro');
            $client->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1, 'currency' => 'usd']);
            self::assertSame([Quote::REASON_DISABLED, 1900, []], [$quote->reason, $quote->price, $quote->setCookie]);
            self::assertSame([], $transport->requests);
        }
        self::assertCount(2, $this->errors);
        self::assertStringContainsString('secretKey is not a pp_sk_live_', $this->errors[0]);
    }

    public function testWithoutEd25519TheSdkIsOffWithOneError(): void
    {
        Ed25519::$mode = Ed25519::NONE;
        [$client, $transport] = $this->client();
        self::assertSame(Quote::REASON_DISABLED, $client->getPrice('pro')->reason);
        self::assertSame([], $transport->requests);
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('Ed25519 is unavailable', $this->errors[0]);
    }

    public function testThrowingOnErrorChangesNothing(): void
    {
        [$client] = $this->client(null, ['onError' => static function (): void {
            throw new \RuntimeException('logger down');
        }]);
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro')->reason);
        $client->trackPurchase(['provider' => 'nope', 'orderId' => 'x', 'amount' => 1, 'currency' => 'usd']);
        $client->flush();
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro', ['request' => self::BROWSER])->reason);
    }

    public function testRejectsInvalidOptions(): void
    {
        $cases = [
            ['products' => ['pro' => ['currency' => 'usd', 'fallbackPrice' => 0]]],
            ['products' => ['pro' => ['currency' => 'usd', 'fallbackPrice' => 19.5]]],
            ['products' => ['pro' => ['currency' => 'dollars', 'fallbackPrice' => 1900]]],
            ['products' => ['pro' => ['currency' => 'usd', 'fallbackPrice' => 1900, 'minPrice' => 10, 'maxPrice' => 5]]],
            ['products' => self::PRODUCTS, 'cookieName' => 'bad name;'],
            ['products' => self::PRODUCTS, 'secretkey' => self::SECRET_KEY],
            ['products' => self::PRODUCTS, 'timeoutMs' => -1],
        ];
        foreach ($cases as $options) {
            try {
                new Leverprice($options + ['secretKey' => self::SECRET_KEY]);
                self::fail('accepted ' . json_encode($options, JSON_THROW_ON_ERROR));
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('Leverprice', $e->getMessage());
            }
        }
    }

    public function testUnknownProductIsACallerBug(): void
    {
        [$client] = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown product "nope"');
        $client->getPrice('nope');
    }

    public function testRootPublicKeysAreRaw32ByteEd25519Keys(): void
    {
        self::assertNotEmpty(Leverprice::ROOT_PUBLIC_KEYS);
        foreach (Leverprice::ROOT_PUBLIC_KEYS as $id => $key) {
            self::assertMatchesRegularExpression('/^r\d+$/D', $id);
            self::assertSame(32, strlen(Util::b64urlDecode($key)));
        }
        self::assertSame(1, Leverprice::MIN_KEYSET_VERSION);
        self::assertSame('0.3.0', Leverprice::SDK_VERSION);
    }

    public function testColdStartThatTimesOutReturnsNoConfigWithinTimeout(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $slow = new \ArrayObject([true]);
        $api = self::vectorApi();
        [$client, $transport] = $this->vectorClient($clock, static function (HttpRequest $r) use ($clock, $slow, $api): HttpResponse {
            if ($slow[0] === true && str_ends_with($r->url, '/v1/config')) {
                $clock->advance($r->timeoutMs);

                throw new TransportException('timed out', true);
            }

            return $api($r);
        });

        $start = $clock->now;
        $quote = $client->getPrice('basic', ['request' => self::BROWSER]);
        self::assertSame(Quote::REASON_NO_CONFIG, $quote->reason);
        self::assertSame(['__pp_f=1; Path=/; Max-Age=1800; SameSite=Lax; HttpOnly; Secure'], $quote->setCookie);
        self::assertLessThanOrEqual(500, $clock->now - $start);
        self::assertSame(500, $transport->configRequests()[0]->timeoutMs);

        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $transport->configRequests());
        self::assertSame([], $this->errors);

        $slow[0] = false;
        $client->terminate();
        self::assertSame(10000, $transport->configRequests()[1]->timeoutMs);
        self::assertSame(Quote::REASON_BASE_PRICE, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
    }

    public function testStalledFetchIsReplacedAfterFifteenSeconds(): void
    {
        $clock = new FakeClock(1800000000000);
        [$client, $transport] = $this->client(static function (HttpRequest $r) use ($clock): HttpResponse {
            $clock->advance($r->timeoutMs);

            throw new TransportException('timed out', true);
        }, ['timeoutMs' => 20], $clock);

        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro')->reason);
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro')->reason);
        self::assertCount(1, $transport->requests);
        $clock->advance(16000);
        $client->getPrice('pro');
        self::assertCount(2, $transport->requests);
    }

    public function testOutageSetsThePinAndPinnedBrowsersKeepTheFallback(): void
    {
        $clock = new FakeClock(self::vectorNow());
        [$client, $transport] = $this->vectorClient($clock, static fn(): HttpResponse => new HttpResponse(503, [], '{"error":{"code":"unavailable"}}'));
        $request = self::BROWSER;

        $quote = $client->getPrice('pro', ['request' => $request]);
        self::assertSame([Quote::REASON_NO_CONFIG, 1900, true], [$quote->reason, $quote->price, $quote->isFallback]);
        self::assertSame(['Leverprice config: HTTP 503 (unavailable)'], $this->errors);

        $pinned = $client->getPrice('pro', ['request' => $request + ['cookie' => '__pp_f=1']]);
        self::assertSame(Quote::REASON_PINNED_FALLBACK, $pinned->reason);
        self::assertSame(['__pp_f=1; Path=/; Max-Age=1800; SameSite=Lax; HttpOnly; Secure'], $pinned->setCookie);
        self::assertCount(1, $transport->configRequests());

        $clock->advance(1001);
        $client->getPrice('pro', ['request' => $request + ['cookie' => '__pp_f=1']]);
        $client->terminate();
        self::assertCount(2, $transport->configRequests());
    }

    public function testQuotesOfOneRequestObjectSharePinAndVisitor(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $healthy = new \ArrayObject([false]);
        $api = self::vectorApi();
        [$client] = $this->vectorClient($clock, static function (HttpRequest $r) use ($healthy, $api): HttpResponse {
            return $healthy[0] === true ? $api($r) : new HttpResponse(503, [], '');
        });
        $request = new \ArrayObject();

        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro', ['request' => $request])->reason);
        $healthy[0] = true;
        $clock->advance(60000);
        self::assertSame(Quote::REASON_PINNED_FALLBACK, $client->getPrice('pro', ['request' => $request])->reason);

        $page = (object) ['headers' => self::BROWSER];
        $first = $client->getPrice('pro', ['request' => $page]);
        $second = $client->getPrice('pro', ['request' => $page]);
        self::assertSame(Quote::REASON_EXPERIMENT, $first->reason);
        self::assertCount(1, $first->setCookie);
        self::assertSame($first->visitorId, $second->visitorId);
        self::assertSame([], $second->setCookie);

        $other = $client->getPrice('pro', ['request' => self::BROWSER]);
        self::assertNotSame($first->visitorId, $other->visitorId);
    }

    public function testNotModifiedRevalidationKeepsTheConfig(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $api = self::vectorApi();
        [$client, $transport] = $this->vectorClient($clock, static function (HttpRequest $r) use ($api): HttpResponse {
            return isset($r->headers['If-None-Match']) ? new HttpResponse(304) : $api($r);
        });

        self::assertSame(Quote::REASON_BASE_PRICE, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        $clock->advance(30000);
        self::assertSame(Quote::REASON_BASE_PRICE, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $transport->configRequests());
        $client->terminate();

        $requests = $transport->configRequests();
        self::assertCount(2, $requests);
        self::assertSame('"v5"', $requests[1]->headers['If-None-Match'] ?? null);
        $clock->advance(86400000 - 1000);
        self::assertSame(Quote::REASON_BASE_PRICE, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], array_values(array_filter($this->errors, static fn(string $e): bool => !str_contains($e, 'malformed products'))));
    }

    public function testNewerConfigVersionTriggersAnUnconditionalFetch(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $api = self::vectorApi();
        [$client, $transport] = $this->vectorClient($clock, static function (HttpRequest $r) use ($api): HttpResponse {
            if (str_ends_with($r->url, '/v1/events')) {
                return new HttpResponse(202, ['Leverprice-Config-Version' => '6'], '{}');
            }

            return $api($r);
        });

        $client->getPrice('pro', ['request' => self::BROWSER]);
        $client->flush();
        $client->getPrice('pro', ['request' => self::BROWSER]);
        $client->terminate();

        $requests = $transport->configRequests();
        self::assertCount(2, $requests);
        self::assertArrayNotHasKey('If-None-Match', $requests[1]->headers);

        $client->getPrice('pro', ['request' => self::BROWSER]);
        $client->terminate();
        self::assertCount(2, $transport->configRequests());
        $clock->advance(5000);
        $client->getPrice('pro', ['request' => self::BROWSER]);
        $client->terminate();
        self::assertCount(3, $transport->configRequests());
    }

    public function testBackoffAfterFailuresAndPreloadResetsIt(): void
    {
        $clock = new FakeClock(1800000000000);
        [$client, $transport] = $this->client(null, [], $clock);
        $client->getPrice('pro');
        $client->getPrice('pro');
        self::assertCount(1, $transport->requests);
        $clock->advance(1000);
        $client->getPrice('pro');
        self::assertCount(2, $transport->requests);
        $client->getPrice('pro');
        self::assertCount(2, $transport->requests);
        $client->preload();
        self::assertCount(3, $transport->requests);
        $client->getPrice('pro');
        self::assertCount(4, $transport->requests);
    }

    public function testTrackPurchaseSendsServerFieldNames(): void
    {
        [$client, $transport] = $this->client();
        $client->trackPurchase([
            'provider' => 'stripe',
            'orderId' => 'pi_1',
            'amount' => 2900.4,
            'currency' => 'EUR',
            'timestamp' => 1760000000,
            'metadata' => ['pp_exp' => 'exp_A', 'pp_var' => 'var_2', 'pp_vid' => 'abc', 'pp_sig' => '0123456789abcdef', 'other' => 'x'],
        ]);

        $request = $transport->eventRequests()[0];
        self::assertSame('Bearer ' . self::SECRET_KEY, $request->headers['Authorization']);
        self::assertSame('leverprice-sdk-php/0.3.0', $request->headers['User-Agent']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertArrayNotHasKey('Origin', $request->headers);
        self::assertSame('https://api.leverprice.com/v1/events', $request->url);
        $body = self::arr(json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['name' => 'leverprice-php', 'version' => '0.3.0'], $body['sdk']);
        self::assertSame([[
            'type' => 'purchase',
            'provider' => 'stripe',
            'orderId' => 'pi_1',
            'amount' => 2900,
            'currency' => 'eur',
            'timestamp' => 1760000000000,
            'attribution' => ['experimentId' => 'exp_A', 'variantId' => 'var_2', 'visitorId' => 'abc', 'signature' => '0123456789abcdef'],
        ]], $body['events']);
    }

    public function testAttributionFromObjectsAndPartialMetadata(): void
    {
        [$client, $transport] = $this->client();
        $client->trackPurchase([
            'provider' => 'polar',
            'orderId' => 42,
            'amount' => 100,
            'currency' => 'usd',
            'timestamp' => new \DateTimeImmutable('@1760000000.250'),
            'metadata' => new \ArrayObject(['pp_exp' => 'exp_A', 'pp_var' => 'var_2', 'pp_vid' => 'abc', 'pp_sig' => 'NOT-A-TAG']),
        ]);
        $client->trackPurchase(['provider' => 'polar', 'orderId' => 'o2', 'amount' => 100, 'currency' => 'usd', 'metadata' => (object) ['pp_exp' => 'e']]);

        $events = $transport->sentEvents();
        self::assertSame('42', $events[0]['orderId']);
        self::assertSame(1760000000250, $events[0]['timestamp']);
        self::assertSame(['experimentId' => 'exp_A', 'variantId' => 'var_2', 'visitorId' => 'abc', 'signature' => null], $events[0]['attribution']);
        self::assertNull($events[1]['attribution']);
    }

    public function testInvalidEventsAreDroppedInsteadOfPoisoningTheBatch(): void
    {
        [$client, $transport] = $this->client();
        // @phpstan-ignore argument.type
        $client->trackPurchase(['provider' => 'stripe', 'orderId' => null, 'amount' => 1, 'currency' => 'usd']);
        $client->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_ok', 'amount' => NAN, 'currency' => 'usd']);
        $client->trackRefund(['provider' => 'stripe', 'orderId' => 'pi_ok', 'currency' => 'usd', 'refundedTotal' => -5]);
        $client->trackRefund(['provider' => 'stripe', 'orderId' => 'pi_ok', 'currency' => 'usd', 'refundId' => 're_1']);
        $client->trackRefund(['provider' => 'stripe', 'orderId' => 'pi_ok', 'currency' => 'usd', 'refundedTotal' => 500]);
        $client->trackRefund(['provider' => 'dodo', 'orderId' => 'pi_ok', 'currency' => 'usd', 'refundId' => 're_2', 'amount' => 5]);

        self::assertCount(4, $this->errors);
        self::assertStringStartsWith('Leverprice: dropped invalid purchase event: orderId', $this->errors[0]);
        self::assertSame([
            ['type' => 'refund', 'provider' => 'stripe', 'orderId' => 'pi_ok', 'currency' => 'usd', 'timestamp' => 1800000000000, 'refundedTotal' => 500],
            ['type' => 'refund', 'provider' => 'dodo', 'orderId' => 'pi_ok', 'currency' => 'usd', 'timestamp' => 1800000000000, 'refundId' => 're_2', 'amount' => 5],
        ], $transport->sentEvents());
    }

    public function testFailedSendsRequeuePurchasesAndDropExposures(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $statuses = [503, 503, 503, 202];
        $api = self::vectorApi();
        [$client, $transport] = $this->vectorClient($clock, static function (HttpRequest $r) use (&$statuses, $api): HttpResponse {
            return str_ends_with($r->url, '/v1/events') ? new HttpResponse(array_shift($statuses) ?? 202) : $api($r);
        });

        self::assertSame(Quote::REASON_EXPERIMENT, $client->getPrice('pro', ['request' => self::BROWSER])->reason);
        $client->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1900, 'currency' => 'usd']);
        self::assertCount(3, $transport->eventRequests());
        self::assertSame([250, 1000], $clock->sleeps);
        self::assertContains('Leverprice events: HTTP 503', $this->errors);

        $client->flush();
        self::assertCount(4, $transport->eventRequests());
        $last = $transport->eventRequests()[3];
        $events = self::arr(self::arr(json_decode((string) $last->body, true, 512, JSON_THROW_ON_ERROR))['events']);
        self::assertCount(1, $events);
        self::assertSame('pi_1', self::arr($events[0])['orderId']);
        $client->flush();
        self::assertCount(4, $transport->eventRequests());
    }

    public function testPermanentRejectionIsNotRetried(): void
    {
        [$client, $transport] = $this->client(static fn(): HttpResponse => new HttpResponse(400, [], str_repeat('x', 400)));
        $client->trackRefund(['provider' => 'dodo', 'orderId' => 'p_1', 'refundId' => 'r_1', 'amount' => 5, 'currency' => 'usd']);
        $client->flush();
        self::assertCount(1, $transport->eventRequests());
        self::assertSame('Leverprice events rejected: HTTP 400 ' . str_repeat('x', 300), $this->errors[0]);
    }

    public function testHangingApiGivesUpAfterTheRequestTimeout(): void
    {
        $clock = new FakeClock(1800000000000);
        [$client, $transport] = $this->client(static function (HttpRequest $r) use ($clock): HttpResponse {
            $clock->advance($r->timeoutMs);

            throw new TransportException('Operation timed out', true);
        }, [], $clock);
        $client->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1, 'currency' => 'usd']);
        self::assertCount(3, $transport->requests);
        self::assertSame(3 * 10000 + 1250, $clock->now - 1800000000000);
        self::assertSame('Leverprice: POST /v1/events timed out after 10000 ms', end($this->errors));
    }

    public function testManualExposureIsLoggedOnce(): void
    {
        [$client, $transport] = $this->vectorClient(new FakeClock(self::vectorNow()));
        $quote = $client->getPrice('pro', ['request' => self::BROWSER, 'exposure' => 'manual']);
        self::assertFalse($quote->exposureLogged);
        self::assertTrue($client->logExposure($quote));
        self::assertTrue($quote->exposureLogged);
        self::assertFalse($client->logExposure($quote));
        $client->flush();
        $events = $transport->sentEvents();
        self::assertCount(1, $events);
        self::assertSame($quote->visitorId, $events[0]['visitorId']);
        self::assertFalse($client->logExposure($client->getPrice('basic', ['request' => self::BROWSER])));
    }

    public function testExposuresFlushAtTerminate(): void
    {
        [$client, $transport] = $this->vectorClient(new FakeClock(self::vectorNow()));
        $client->getPrice('pro', ['request' => self::BROWSER, 'userId' => 7]);
        self::assertSame([], $transport->eventRequests());
        $client->terminate();
        self::assertCount(1, $transport->sentEvents());
    }

    public function testQuoteHelpers(): void
    {
        [$client] = $this->vectorClient(new FakeClock(self::vectorNow()));
        $quote = $client->getPrice('pro', ['request' => self::BROWSER, 'userId' => 'user_42']);
        self::assertSame(Quote::REASON_EXPERIMENT, $quote->reason);
        self::assertSame('ad4f85c841e8485cc9a683a318147ba0', $quote->visitorId);
        $json = json_decode(json_encode($quote, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($quote->toArray(), $json);
        $empty = json_encode($client->getPrice('basic', ['request' => self::BROWSER]), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"metadata":{}', $empty);
    }
}
