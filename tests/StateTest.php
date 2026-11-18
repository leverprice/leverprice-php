<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Http\TransportException;
use Leverprice\Internal\Acceptor;
use Leverprice\Internal\SharedState;
use Leverprice\Internal\Util;
use Leverprice\Leverprice;
use Leverprice\Quote;
use Leverprice\State\ApcuStore;
use Leverprice\State\FileStore;
use Leverprice\State\Psr16Store;
use Leverprice\State\StateStore;
use Leverprice\Tests\Support\ArrayCache;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\FakeTransport;
use Leverprice\Tests\Support\TestCase;
use Leverprice\Tests\Support\Vectors;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class StateTest extends TestCase
{
    private const BROWSER = ['user-agent' => self::UA];

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leverprice-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @return \Closure(HttpRequest): HttpResponse */
    private static function down(): \Closure
    {
        return static fn(): HttpResponse => throw new TransportException('connection refused');
    }

    /**
     * @param (callable(HttpRequest): HttpResponse)|null $handler
     * @return array{Leverprice, FakeTransport}
     */
    private function process(FakeClock $clock, ?callable $handler = null, ?StateStore $store = null): array
    {
        return $this->vectorClient($clock, $handler, ['cache' => $store ?? new FileStore($this->directory)]);
    }

    private function stateFile(): string
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . 'leverprice-*.state') ?: [];
        self::assertCount(1, $files);

        return $files[0];
    }

    public function testStateIsSharedBetweenRequestsThroughTheFileStore(): void
    {
        $clock = new FakeClock(self::vectorNow());
        [$first, $firstTransport] = $this->process($clock);
        self::assertSame(Quote::REASON_BASE_PRICE, $first->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $firstTransport->configRequests());

        $clock->advance(1000);
        [$second, $secondTransport] = $this->process($clock, self::down());
        self::assertSame(Quote::REASON_BASE_PRICE, $second->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], $secondTransport->requests);

        $clock->advance(30000);
        $api = self::vectorApi();
        [$third, $thirdTransport] = $this->process($clock, static fn(HttpRequest $r): HttpResponse => isset($r->headers['If-None-Match']) ? new HttpResponse(304) : $api($r));
        self::assertSame(Quote::REASON_BASE_PRICE, $third->getPrice('basic', ['request' => self::BROWSER])->reason);
        $third->terminate();
        self::assertSame('"v5"', $thirdTransport->configRequests()[0]->headers['If-None-Match'] ?? null);

        [$fourth, $fourthTransport] = $this->process($clock, self::down());
        $fourth->getPrice('basic', ['request' => self::BROWSER]);
        $fourth->terminate();
        self::assertSame([], $fourthTransport->requests);

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0o600, fileperms($this->stateFile()) & 0o777);
        }
    }

    public function testRegionalPricesSurviveTheCacheBetweenRequests(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $options = ['cache' => new FileStore($this->directory), 'localCurrencies' => true];
        [$first] = $this->vectorClient($clock, null, $options);
        $first->getPrice('basic', ['request' => self::BROWSER]);

        $clock->advance(1000);
        [$second, $transport] = $this->vectorClient($clock, self::down(), $options);
        $quote = $second->getPrice('geo', ['request' => self::BROWSER, 'country' => 'IN']);
        self::assertSame([99900, 'inr', 'IN', true], [$quote->price, $quote->currency, $quote->country, $quote->regional]);
        $variant = $second->getPrice('geox', ['request' => self::BROWSER, 'country' => 'BR', 'userId' => 'user_42']);
        self::assertSame([2700, 'usd', 'BR', true], [$variant->price, $variant->currency, $variant->country, $variant->regional]);
        self::assertSame([], $transport->requests);
    }

    public function testTheProjectCountryHeaderSurvivesTheCacheBetweenRequests(): void
    {
        foreach ([new FileStore($this->directory), new Psr16Store(new ArrayCache())] as $store) {
            $clock = new FakeClock(self::vectorNow());
            [$first] = $this->process($clock, null, $store);
            $first->getPrice('basic', ['request' => self::BROWSER]);

            $clock->advance(1000);
            [$second, $transport] = $this->process($clock, self::down(), $store);
            $quote = $second->getPrice('geo', ['request' => self::BROWSER + ['X-Geo-Country' => 'br']]);
            self::assertSame([2000, 'usd', 'BR', true], [$quote->price, $quote->currency, $quote->country, $quote->regional]);
            self::assertSame([], $transport->requests);
        }
    }

    public function testTestsByCountrySurviveTheCacheBetweenRequests(): void
    {
        $guard = ['currency' => 'usd', 'fallbackPrice' => 2900];
        foreach ([new FileStore($this->directory), new Psr16Store(new ArrayCache())] as $store) {
            $clock = new FakeClock(self::vectorNow());
            $options = ['cache' => $store, 'products' => ['local' => $guard, 'split' => $guard]];
            [$first] = $this->vectorClient($clock, null, $options);
            $first->getPrice('split', ['request' => self::BROWSER]);

            $clock->advance(1000);
            [$second, $transport] = $this->vectorClient($clock, self::down(), $options);
            self::assertSame('var_s3', $second->getPrice('split', ['request' => self::BROWSER, 'country' => 'IN'])->variantId);
            self::assertSame(Quote::REASON_EXPERIMENT, $second->getPrice('local', ['request' => self::BROWSER, 'country' => 'CA'])->reason);
            self::assertSame(Quote::REASON_BASE_PRICE, $second->getPrice('local', ['request' => self::BROWSER, 'country' => 'US'])->reason);
            self::assertSame([], $transport->requests);
        }
    }

    public function testCachedConfigWithoutRegionsStillLoads(): void
    {
        $legacy = null;
        foreach (self::arr(Vectors::objects()->trust) as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            if (self::str($case->name) === 'config without malformed products drops nothing') {
                $legacy = self::arr($case->steps)[0];
            }
        }
        self::assertInstanceOf(\stdClass::class, $legacy);
        self::assertInstanceOf(\stdClass::class, $legacy->envelope);
        self::assertStringNotContainsString('"regions"', Util::b64urlDecode(self::str($legacy->envelope->payload)));

        $clock = new FakeClock(self::vectorNow());
        $state = new SharedState();
        $state->envelope = self::envelope($legacy->envelope);
        $state->etag = '"v5"';
        $state->fetchedAt = $clock->now;
        $state->configVersion = 5;
        $macKey = hash_hmac('sha256', 'leverprice:state:v1', self::str(Vectors::arrays()['secretKey']), true);

        $store = new FileStore($this->directory);
        [$probe] = $this->process($clock, self::down(), $store);
        $probe->getPrice('basic', ['request' => self::BROWSER]);
        $key = basename($this->stateFile(), '.state');
        self::assertTrue($store->write(substr($key, strlen('leverprice-')), $state->encode($macKey)));

        [$client, $transport] = $this->process($clock, self::down(), $store);
        $quote = $client->getPrice('basic', ['request' => self::BROWSER, 'country' => 'IN']);
        self::assertSame([Quote::REASON_BASE_PRICE, 900, 'usd', 'IN', false], [$quote->reason, $quote->price, $quote->currency, $quote->country, $quote->regional]);
        self::assertSame([], $transport->requests);
    }

    public function testTamperedStateFileIsIgnored(): void
    {
        $clock = new FakeClock(self::vectorNow());
        [$first] = $this->process($clock);
        $first->getPrice('basic', ['request' => self::BROWSER]);

        $file = $this->stateFile();
        $contents = (string) file_get_contents($file);
        file_put_contents($file, str_replace('"fetchedAt":', '"fetchedAt":1', $contents));
        [$second, $transport] = $this->process($clock, self::down());
        self::assertSame(Quote::REASON_NO_CONFIG, $second->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $transport->requests);

        file_put_contents($file, 'garbage');
        [$third] = $this->process($clock, self::down());
        self::assertSame(Quote::REASON_NO_CONFIG, $third->getPrice('basic', ['request' => self::BROWSER])->reason);
    }

    public function testCachedEnvelopeIsReverifiedEvenWithAValidMac(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $config = Vectors::objects()->config;
        self::assertInstanceOf(\stdClass::class, $config);
        $forged = clone $config;
        $forged->payload = self::str($config->payload) . 'A';

        $state = new SharedState();
        $state->envelope = self::envelope($forged);
        $state->etag = '"v5"';
        $state->fetchedAt = $clock->now;
        $state->configVersion = 5;
        $macKey = hash_hmac('sha256', 'leverprice:state:v1', self::str(Vectors::arrays()['secretKey']), true);

        $store = new FileStore($this->directory);
        [$probe] = $this->process($clock, self::down(), $store);
        $probe->getPrice('basic', ['request' => self::BROWSER]);
        $key = basename($this->stateFile(), '.state');
        self::assertTrue($store->write(substr($key, strlen('leverprice-')), $state->encode($macKey)));

        [$client, $transport] = $this->process($clock, self::down(), $store);
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $transport->requests);
    }

    public function testKeysetVersionAndConfigVersionStayMonotonicAcrossRequests(): void
    {
        $steps = [];
        foreach (self::arr(Vectors::objects()->trust) as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            if (str_starts_with(self::str($case->name), 'revocation')) {
                $steps = self::arr($case->steps);
            }
        }
        self::assertCount(2, $steps);
        [$newer, $revoked] = $steps;
        self::assertInstanceOf(\stdClass::class, $newer);
        self::assertInstanceOf(\stdClass::class, $revoked);

        $clock = new FakeClock(self::vectorNow());
        [$first] = $this->process($clock, self::vectorApi(self::envelope($newer->envelope)));
        $first->refresh();
        self::assertSame(Quote::REASON_BASE_PRICE, $first->getPrice('basic', ['request' => self::BROWSER])->reason);

        [$second] = $this->process($clock, self::vectorApi(self::envelope($revoked->envelope)));
        $second->refresh();
        self::assertStringContainsString('revoked keys cannot come back', end($this->errors) ?: '');

        $this->errors = [];
        [$third] = $this->process($clock, self::vectorApi());
        $third->refresh();
        self::assertNotSame([], $this->errors);
        self::assertSame(Quote::REASON_BASE_PRICE, $third->getPrice('basic', ['request' => self::BROWSER])->reason);
    }

    public function testStoredKeysetVersionRejectsOlderCachedKeysets(): void
    {
        $newer = null;
        foreach (self::arr(Vectors::objects()->trust) as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            if (str_starts_with(self::str($case->name), 'revocation')) {
                $newer = self::arr($case->steps)[0];
            }
        }
        self::assertInstanceOf(\stdClass::class, $newer);
        self::assertInstanceOf(\stdClass::class, $newer->envelope);
        self::assertInstanceOf(\stdClass::class, $newer->envelope->keyset);
        $keyset = $newer->envelope->keyset;

        $clock = new FakeClock(self::vectorNow());
        $macKey = hash_hmac('sha256', 'leverprice:state:v1', self::str(Vectors::arrays()['secretKey']), true);
        $store = new FileStore($this->directory);
        foreach ([true, false] as $withKeyset) {
            [$probe] = $this->process($clock, self::vectorApi(), $store);
            $probe->getPrice('basic', ['request' => self::BROWSER]);
            $file = $this->stateFile();
            $state = SharedState::decode((string) file_get_contents($file), $macKey);
            self::assertNotNull($state);
            self::assertSame(1, $state->keysetVersion);
            $state->keysetVersion = 2;
            $state->keyset = $withKeyset
                ? ['payload' => self::str($keyset->payload), 'signature' => self::str($keyset->signature), 'rootKeyId' => self::str($keyset->rootKeyId)]
                : null;
            file_put_contents($file, $state->encode($macKey));

            [$client, $transport] = $this->process($clock, self::down(), $store);
            self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
            self::assertCount(1, $transport->requests);
            unlink($file);
        }
    }

    public function testBackoffIsShared(): void
    {
        $clock = new FakeClock(self::vectorNow());
        [$first, $firstTransport] = $this->process($clock, static fn(): HttpResponse => new HttpResponse(503));
        self::assertSame(Quote::REASON_NO_CONFIG, $first->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertCount(1, $firstTransport->requests);

        [$second, $secondTransport] = $this->process($clock, self::vectorApi());
        self::assertSame(Quote::REASON_NO_CONFIG, $second->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], $secondTransport->requests);

        $clock->advance(1000);
        [$third] = $this->process($clock, self::vectorApi());
        self::assertSame(Quote::REASON_BASE_PRICE, $third->getPrice('basic', ['request' => self::BROWSER])->reason);
    }

    public function testConcurrentColdStartWaitsForTheFetchInFlight(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $store = new FileStore($this->directory);
        $hang = static function (HttpRequest $r) use ($clock): HttpResponse {
            $clock->advance($r->timeoutMs);

            throw new TransportException('timed out', true);
        };
        [$first] = $this->process($clock, $hang, $store);
        self::assertSame(Quote::REASON_NO_CONFIG, $first->getPrice('basic', ['request' => self::BROWSER])->reason);

        $clock->now -= 400;
        [$second, $secondTransport] = $this->process($clock, self::vectorApi(), $store);
        $start = $clock->now;
        self::assertSame(Quote::REASON_NO_CONFIG, $second->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], $secondTransport->requests);
        self::assertSame(400, $clock->now - $start);

        [$third, $thirdTransport] = $this->process($clock, self::vectorApi(), $store);
        self::assertSame(Quote::REASON_NO_CONFIG, $third->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], $thirdTransport->requests);
    }

    public function testDefaultStoreIsApcuOrATempFile(): void
    {
        $client = new Leverprice(['secretKey' => self::SECRET_KEY, 'products' => self::defaultGuards(), 'registerShutdown' => false]);
        $store = (new \ReflectionProperty(Leverprice::class, 'store'))->getValue($client);
        self::assertInstanceOf(ApcuStore::isSupported() ? ApcuStore::class : FileStore::class, $store);
    }

    #[RequiresPhpExtension('apcu')]
    public function testApcuStore(): void
    {
        if (!ApcuStore::isSupported()) {
            self::markTestSkipped('APCu is loaded but disabled (apc.enable_cli=0)');
        }
        $store = new ApcuStore();
        $key = 'leverprice.test.' . bin2hex(random_bytes(4));
        self::assertNull($store->read($key));
        self::assertTrue($store->write($key, 'value'));
        self::assertSame('value', $store->read($key));
    }

    public function testPsr16CacheIsShared(): void
    {
        $clock = new FakeClock(self::vectorNow());
        $cache = new ArrayCache();
        [$first] = $this->vectorClient($clock, null, ['cache' => $cache]);
        $first->getPrice('basic', ['request' => self::BROWSER]);
        self::assertCount(1, $cache->values);
        self::assertMatchesRegularExpression('/^leverprice\.v1\.' . Acceptor::fingerprint(self::str(Vectors::arrays()['secretKey'])) . '\.[0-9a-f]{16}$/D', (string) array_key_first($cache->values));

        [$second, $transport] = $this->vectorClient($clock, self::down(), ['cache' => new Psr16Store($cache)]);
        self::assertSame(Quote::REASON_BASE_PRICE, $second->getPrice('basic', ['request' => self::BROWSER])->reason);
        self::assertSame([], $transport->requests);
    }

    public function testUnwritableStoreIsReportedOnce(): void
    {
        $clock = new FakeClock(self::vectorNow());
        [$client] = $this->vectorClient($clock, null, ['cache' => new FileStore($this->directory . DIRECTORY_SEPARATOR . 'missing')]);
        self::assertSame(Quote::REASON_BASE_PRICE, $client->getPrice('basic', ['request' => self::BROWSER])->reason);
        $client->getPrice('basic', ['request' => self::BROWSER]);
        $saveErrors = array_filter($this->errors, static fn(string $e): bool => str_contains($e, 'could not save state'));
        self::assertCount(1, $saveErrors);
    }
}
