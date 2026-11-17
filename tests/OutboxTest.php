<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Internal\Acceptor;
use Leverprice\Internal\Outbox;
use Leverprice\Leverprice;
use Leverprice\State\FileStore;
use Leverprice\State\MemoryStore;
use Leverprice\State\Psr16Store;
use Leverprice\State\StateStore;
use Leverprice\Tests\Support\ArrayCache;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\FakeTransport;
use Leverprice\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OutboxTest extends TestCase
{
    private const NOW = 1800000000000;
    private const PRODUCTS = ['pro' => ['currency' => 'usd', 'fallbackPrice' => 1900]];

    /** @var list<string> */
    private static array $directories = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{\Closure(): StateStore}> */
    public static function stores(): iterable
    {
        yield 'file' => [static function (): StateStore {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leverprice-outbox-' . bin2hex(random_bytes(6));
            mkdir($directory, 0o700);
            self::$directories[] = $directory;

            return new FileStore($directory);
        }];
        yield 'memory' => [static fn(): StateStore => new MemoryStore()];
        yield 'psr-16' => [static fn(): StateStore => new Psr16Store(new ArrayCache())];
    }

    /**
     * @param (callable(HttpRequest): HttpResponse)|null $events
     * @return array{Leverprice, FakeTransport}
     */
    private function process(StateStore $store, FakeClock $clock, ?callable $events = null): array
    {
        $events ??= static fn(): HttpResponse => new HttpResponse(202, [], '{}');
        $transport = new FakeTransport(static fn(HttpRequest $r): HttpResponse => str_ends_with($r->url, '/v1/config')
            ? new HttpResponse(503, [], '{}')
            : $events($r));
        $client = new Leverprice([
            'secretKey' => self::SECRET_KEY,
            'products' => self::PRODUCTS,
            'transport' => $transport,
            'cache' => $store,
            'clock' => $clock,
            'registerShutdown' => false,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e->getMessage();
            },
        ]);

        return [$client, $transport];
    }

    /** @return \Closure(HttpRequest): HttpResponse */
    private static function down(): \Closure
    {
        return static fn(): HttpResponse => new HttpResponse(503, [], '{}');
    }

    /** @return array{provider: string, orderId: string, amount: int, currency: string} */
    private static function purchase(string $orderId): array
    {
        return ['provider' => 'stripe', 'orderId' => $orderId, 'amount' => 1900, 'currency' => 'usd'];
    }

    /** @return list<string> */
    private static function orderIds(FakeTransport $transport): array
    {
        return array_map(static fn(array $e): string => self::str($e['orderId'] ?? null), $transport->sentEvents());
    }

    private static function outboxKey(): string
    {
        return 'leverprice.v1.' . Acceptor::fingerprint(self::SECRET_KEY) . '.'
            . substr(hash('sha256', "https://api.leverprice.com\n" . json_encode(Leverprice::ROOT_PUBLIC_KEYS, JSON_THROW_ON_ERROR)), 0, 16)
            . '.outbox';
    }

    /** @return list<string> */
    private static function storedOrderIds(StateStore $store): array
    {
        $blob = $store->read(self::outboxKey());
        if ($blob === null) {
            return [];
        }
        $record = self::arr(json_decode(substr($blob, 65), true, 512, JSON_THROW_ON_ERROR));

        return array_values(array_map(static fn(mixed $e): string => self::str(self::arr($e)['orderId']), self::arr($record['events'])));
    }

    private static function outbox(StateStore $store, string $owner = 'test'): Outbox
    {
        return new Outbox($store, self::outboxKey(), hash_hmac('sha256', 'leverprice:state:v1', self::SECRET_KEY, true), $owner);
    }

    /** @param \Closure(): StateStore $makeStore */
    #[DataProvider('stores')]
    public function testUndeliveredPurchaseIsSentByTheNextRequestExactlyOnce(\Closure $makeStore): void
    {
        $store = $makeStore();
        $clock = new FakeClock(self::NOW);

        [$first, $firstTransport] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        self::assertCount(3, $firstTransport->eventRequests());
        self::assertSame(['pi_1'], self::storedOrderIds($store));

        $clock->advance(5000);
        [$second, $secondTransport] = $this->process($store, $clock);
        $second->getPrice('pro');
        self::assertSame([], $secondTransport->eventRequests());
        $second->terminate();
        self::assertSame(['pi_1'], self::orderIds($secondTransport));

        [$third, $thirdTransport] = $this->process($store, $clock);
        $third->getPrice('pro');
        $third->terminate();
        $third->trackPurchase(self::purchase('pi_2'));
        self::assertSame(['pi_2'], self::orderIds($thirdTransport));
        self::assertSame([], self::storedOrderIds($store));
        self::assertSame([], array_values(array_filter($this->errors, static fn(string $e): bool => !str_contains($e, 'HTTP 503'))));
    }

    public function testTrackPurchaseSendsTheOutboxFirst(): void
    {
        $store = new MemoryStore();
        $clock = new FakeClock(self::NOW);
        [$first] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        $first->trackRefund(['provider' => 'stripe', 'orderId' => 'pi_1', 'currency' => 'usd', 'refundedTotal' => 100]);

        $clock->advance(60000);
        [$second, $transport] = $this->process($store, $clock);
        $second->trackPurchase(self::purchase('pi_2'));
        self::assertCount(1, $transport->eventRequests());
        self::assertSame(['pi_1', 'pi_1', 'pi_2'], self::orderIds($transport));
        self::assertSame(['purchase', 'refund', 'purchase'], array_column($transport->sentEvents(), 'type'));
    }

    public function testFailingApiDoesNotBlockRequestsOnTheOutbox(): void
    {
        $store = new MemoryStore();
        $clock = new FakeClock(self::NOW);
        [$first, $firstTransport] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        $first->terminate();
        self::assertCount(3, $firstTransport->eventRequests());

        $clock->advance(1000);
        [$second, $secondTransport] = $this->process($store, $clock);
        $second->getPrice('pro');
        $second->terminate();
        self::assertSame([], $secondTransport->eventRequests());

        $second->trackPurchase(self::purchase('pi_2'));
        self::assertSame(['pi_2', 'pi_1'], self::orderIds($secondTransport));
        self::assertCount(2, $secondTransport->eventRequests());
    }

    public function testConcurrentWorkersNeverSendTheSameOutbox(): void
    {
        $store = new MemoryStore();
        $clock = new FakeClock(self::NOW);
        [$first] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        $clock->advance(60000);

        [$other, $otherTransport] = $this->process($store, $clock);
        $inFlight = new \ArrayObject();
        [$worker, $workerTransport] = $this->process($store, $clock, static function () use ($inFlight, $other): HttpResponse {
            if ($inFlight->count() === 0) {
                $inFlight->append(true);
                $other->trackPurchase(self::purchase('pi_3'));
            }

            return new HttpResponse(202, [], '{}');
        });
        $worker->trackPurchase(self::purchase('pi_2'));

        self::assertSame(['pi_1', 'pi_2'], self::orderIds($workerTransport));
        self::assertSame(['pi_3'], self::orderIds($otherTransport));
        self::assertSame([], self::storedOrderIds($store));
    }

    public function testExpiredLeaseCanBeClaimedAgain(): void
    {
        $store = new MemoryStore();
        $event = ['type' => 'purchase', 'provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1, 'currency' => 'usd', 'timestamp' => self::NOW, 'attribution' => null];
        self::assertTrue(self::outbox($store, 'a')->settle([], [$event], false, self::NOW));

        self::assertSame([$event], self::outbox($store, 'a')->claim(self::NOW));
        self::assertNull(self::outbox($store, 'b')->claim(self::NOW + 1000));
        self::assertSame([$event], self::outbox($store, 'a')->claim(self::NOW + 1000));
        self::assertNull(self::outbox($store, 'b')->claim(self::NOW + Outbox::LEASE_MS));
        self::assertSame([$event], self::outbox($store, 'b')->claim(self::NOW + 1000 + Outbox::LEASE_MS));
    }

    public function testCapDropsTheOldestEvents(): void
    {
        $store = new MemoryStore();
        $outbox = self::outbox($store);
        $events = [];
        for ($i = 0; $i < Outbox::MAX_EVENTS + 3; $i++) {
            $events[] = ['type' => 'refund', 'provider' => 'other', 'orderId' => 'o_' . $i, 'currency' => 'usd', 'timestamp' => self::NOW, 'refundedTotal' => $i];
        }
        self::assertTrue($outbox->settle([], $events, false, self::NOW));
        self::assertSame(Outbox::MAX_EVENTS, $outbox->size);
        self::assertSame([
            'Leverprice: event outbox full, dropped refund o_0',
            'Leverprice: event outbox full, dropped refund o_1',
            'Leverprice: event outbox full, dropped refund o_2',
        ], $outbox->takeProblems());
        $claimed = self::outbox($store, 'probe')->claim(self::NOW);
        self::assertIsArray($claimed);
        self::assertSame('o_3', $claimed[0]['orderId']);
    }

    public function testEventsOlderThanSixtyDaysAreDroppedAndReported(): void
    {
        $store = new MemoryStore();
        $old = ['type' => 'purchase', 'provider' => 'stripe', 'orderId' => 'pi_old', 'amount' => 1, 'currency' => 'usd', 'timestamp' => self::NOW, 'attribution' => null];
        self::assertTrue(self::outbox($store)->settle([], [$old], false, self::NOW));

        $clock = new FakeClock(self::NOW + Outbox::MAX_AGE_MS + 1);
        [$client, $transport] = $this->process($store, $clock);
        $client->trackPurchase(self::purchase('pi_new'));
        self::assertSame(['pi_new'], self::orderIds($transport));
        self::assertSame(['Leverprice: dropped 1 purchase/refund event(s) older than 60 days from the outbox'], $this->errors);
    }

    /** @param \Closure(): StateStore $makeStore */
    #[DataProvider('stores')]
    public function testTamperedOutboxIsDiscardedWithOneReport(\Closure $makeStore): void
    {
        $store = $makeStore();
        $clock = new FakeClock(self::NOW);
        [$first] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        $blob = (string) $store->read(self::outboxKey());
        self::assertStringContainsString('"pi_1"', $blob);
        $store->write(self::outboxKey(), str_replace('"pi_1"', '"pi_9"', $blob));

        $clock->advance(60000);
        $this->errors = [];
        [$second, $transport] = $this->process($store, $clock);
        $second->trackPurchase(self::purchase('pi_2'));
        $second->trackPurchase(self::purchase('pi_3'));
        self::assertSame(['pi_2', 'pi_3'], self::orderIds($transport));
        self::assertSame(['Leverprice: discarded an unreadable event outbox'], $this->errors);

        $store->write(self::outboxKey(), "\x00garbage");
        [$third, $thirdTransport] = $this->process($store, $clock);
        $third->trackPurchase(self::purchase('pi_4'));
        self::assertSame(['pi_4'], self::orderIds($thirdTransport));
        self::assertCount(2, $this->errors);
    }

    public function testShutdownWhileBackingOffKeepsMoneyEventsInTheOutbox(): void
    {
        $store = new MemoryStore();
        $clock = new FakeClock(self::NOW);
        [$first, $transport] = $this->process($store, $clock, self::down());
        $first->trackPurchase(self::purchase('pi_1'));
        $first->terminate();
        $first->flush();
        self::assertCount(3, $transport->eventRequests());
        self::assertSame(['pi_1'], self::storedOrderIds($store));

        $clock->advance(60000);
        [$second, $secondTransport] = $this->process($store, $clock);
        $second->flush();
        $second->getPrice('pro');
        $second->terminate();
        self::assertSame(['pi_1'], self::orderIds($secondTransport));
        self::assertSame([], self::storedOrderIds($store));
    }
}
