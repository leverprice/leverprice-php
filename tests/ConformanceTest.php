<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Bots;
use Leverprice\Internal\Acceptor;
use Leverprice\Internal\AssignmentState;
use Leverprice\Internal\Cookie;
use Leverprice\Internal\RequestReader;
use Leverprice\Internal\TrustChain;
use Leverprice\Internal\Util;
use Leverprice\Internal\Wire;
use Leverprice\Leverprice;
use Leverprice\LeverpriceException;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\TestCase;
use Leverprice\Tests\Support\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConformanceTest extends TestCase
{
    /** @return iterable<string, array{\stdClass}> */
    public static function trustCases(): iterable
    {
        foreach (self::objectList(Vectors::objects()->trust) as $case) {
            yield self::str($case->name) => [$case];
        }
    }

    #[DataProvider('trustCases')]
    public function testTrustChainAndAcceptance(\stdClass $case): void
    {
        $vectors = Vectors::arrays();
        /** @var array<string, string> $rootKeys */
        $rootKeys = self::arr($vectors['rootKeys']);
        $min = property_exists($case, 'minKeysetVersion') ? self::int($case->minKeysetVersion) : Leverprice::MIN_KEYSET_VERSION;
        $acceptor = new Acceptor(new TrustChain($rootKeys, $min), Acceptor::fingerprint(self::str($vectors['secretKey'])));

        foreach (self::objectList($case->steps) as $i => $step) {
            $now = property_exists($step, 'now') ? self::int($step->now) : self::vectorNow();
            try {
                $verified = $acceptor->accept($step->envelope, $now);
                $outcome = 'accept';
            } catch (LeverpriceException $e) {
                $verified = null;
                $outcome = 'reject';
            }
            self::assertSame($step->expect, $outcome, sprintf('step %d', $i));
            if ($verified !== null) {
                self::assertSame($step->version, $verified->config->version);
                self::assertSame($step->dropped, $verified->dropped);
            }
        }
    }

    public function testKeyFingerprints(): void
    {
        foreach (self::arr(Vectors::arrays()['keyFingerprints']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['fingerprint'], Acceptor::fingerprint(self::str($case['secretKey'])));
        }
    }

    public function testVisitorIdsAndAttributionTags(): void
    {
        $vectors = Vectors::arrays();
        $key = Util::b64urlDecode(self::str($vectors['assignmentKey']));
        foreach (self::arr($vectors['visitorIds']) as $case) {
            $case = self::arr($case);
            self::assertSame('pp:visitor:v1:' . self::str($case['visitorKey']), $case['message']);
            self::assertSame($case['visitorId'], Util::hmacHex($key, self::str($case['message']), 32));
        }
        foreach (self::arr($vectors['attribution']) as $case) {
            $case = self::arr($case);
            $message = sprintf('pp:attr:v1:%s:%s:%s', self::str($case['experimentId']), self::str($case['variantId']), self::str($case['visitorId']));
            self::assertSame($case['message'], $message);
            self::assertSame($case['signature'], Util::hmacHex($key, $message, 16));
        }
    }

    public function testBucketsAndVariantPicks(): void
    {
        $vectors = Vectors::arrays();
        foreach (self::arr($vectors['buckets']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['bucket'], Util::bucketFor(self::str($case['salt']), self::str($case['visitorId'])));
        }
        foreach (self::arr($vectors['pickVariant']) as $case) {
            $case = self::arr($case);
            /** @var list<int> $weights */
            $weights = self::arr($case['weights']);
            self::assertSame($case['index'], Util::pickVariant($weights, self::int($case['bucket'])));
        }
    }

    public function testSignsCookiesByteForByte(): void
    {
        $key = self::assignmentKey();
        foreach (self::arr(self::arr(Vectors::arrays()['cookies'])['sign']) as $case) {
            $case = self::arr($case);
            $state = self::state($case['state']);
            self::assertSame($case['json'], $state->toJson());
            self::assertSame($case['cookie'], Cookie::sign($key, $state));
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function cookieVerifyCases(): iterable
    {
        foreach (self::arr(self::arr(Vectors::arrays()['cookies'])['verify']) as $case) {
            $case = self::arr($case);
            yield self::str($case['name']) => [$case];
        }
    }

    /** @param array<array-key, mixed> $case */
    #[DataProvider('cookieVerifyCases')]
    public function testVerifiesCookies(array $case): void
    {
        $state = Cookie::verify(self::assignmentKey(), self::str($case['cookie']));
        self::assertSame($case['state'], $state === null ? null : self::plain($state));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function cookieOpCases(): iterable
    {
        foreach (self::arr(self::arr(Vectors::arrays()['cookies'])['ops']) as $case) {
            $case = self::arr($case);
            yield self::str($case['name']) => [$case];
        }
    }

    /** @param array<array-key, mixed> $case */
    #[DataProvider('cookieOpCases')]
    public function testCookieOperations(array $case): void
    {
        $state = self::state($case['state']);
        $op = self::arr($case['op']);
        if (isset($op['assign'])) {
            $assign = self::arr($op['assign']);
            $state->assign(self::str($assign[0]), self::str($assign[1]));
        } else {
            self::assertSame($case['changed'], $state->markExposed(self::str($op['markExposed'])));
        }
        self::assertSame($case['result'], self::plain($state));
    }

    public function testCookieHeadersPrefetchHintsAndSetCookie(): void
    {
        $vectors = Vectors::arrays();
        foreach (self::arr($vectors['cookieHeader']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['values'], RequestReader::cookieValues(self::str($case['header']), self::str($case['name'])), self::str($case['header']));
        }
        foreach (self::arr($vectors['prefetch']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['prefetch'], RequestReader::isPrefetch(self::arr($case['headers'])), self::envelope($case['headers']));
        }
        foreach (self::arr($vectors['setCookie']) as $case) {
            $case = self::arr($case);
            $domain = $case['domain'];
            self::assertSame($case['header'], Cookie::setCookieHeader(
                self::str($case['name']),
                self::str($case['value']),
                self::int($case['maxAge']),
                $case['secure'] === true,
                is_string($domain) ? $domain : null,
            ));
        }
    }

    public function testNormalizesCountries(): void
    {
        foreach (self::arr(Vectors::arrays()['countries']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['country'], RequestReader::normalizeCountry($case['value']), self::envelope($case['value']));
        }
    }

    public function testDetectsBots(): void
    {
        foreach (self::arr(Vectors::arrays()['bots']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['bot'], Bots::isBot(self::str($case['userAgent'])), self::str($case['userAgent']));
        }
    }

    public function testValidatesEventsBeforeQueueing(): void
    {
        foreach (self::arr(Vectors::arrays()['events']) as $case) {
            $case = self::arr($case);
            /** @var array<string, mixed> $event */
            $event = self::arr($case['event']);
            self::assertSame($case['valid'], Wire::eventProblem($event) === null, self::envelope($event));
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function quoteCases(): iterable
    {
        foreach (self::arr(Vectors::arrays()['quotes']) as $case) {
            $case = self::arr($case);
            yield self::str($case['name']) => [$case];
        }
    }

    /** @param array<array-key, mixed> $case */
    #[DataProvider('quoteCases')]
    public function testQuotes(array $case): void
    {
        $products = self::defaultGuards();
        foreach (self::arr($case['guards'] ?? []) as $key => $guard) {
            /** @var array<string, mixed> $guard */
            $products[(string) $key] = $guard;
        }
        $options = ['products' => $products];
        foreach (['countryHeader', 'localCurrencies'] as $key) {
            if (isset($case[$key])) {
                $options[$key] = $case[$key];
            }
        }
        [$client, $transport] = $this->vectorClient(new FakeClock(self::vectorNow()), null, $options);

        $context = ['request' => self::arr($case['headers']), 'userId' => $case['userId']];
        foreach (['exposure', 'bot', 'country'] as $key) {
            if (isset($case[$key])) {
                $context[$key] = $case[$key];
            }
        }
        /** @var array{request?: mixed, cookie?: ?string, userAgent?: ?string, userId?: string|int|null, country?: ?string, exposure?: ?string, bot?: ?bool} $context */
        $quote = $client->getPrice(self::str($case['productKey']), $context);
        $client->flush();

        $actual = $quote->toArray();
        unset($actual['productKey']);
        $actual['exposures'] = array_map(static function (array $event): array {
            unset($event['timestamp']);

            return $event;
        }, $transport->sentEvents());
        $expected = self::arr($case['expect']);
        unset($expected['productKey']);
        ksort($actual);
        ksort($expected);

        self::assertSame($expected, $actual);
        self::assertSame($case['errors'], $this->reported());
    }

    private static function assignmentKey(): string
    {
        return Util::b64urlDecode(self::str(Vectors::arrays()['assignmentKey']));
    }

    private static function state(mixed $raw): AssignmentState
    {
        $raw = self::arr($raw);
        $pairs = [];
        foreach (self::arr($raw['a']) as $pair) {
            $pair = self::arr($pair);
            $pairs[] = [self::str($pair[0]), self::str($pair[1])];
        }
        /** @var list<string> $x */
        $x = self::arr($raw['x']);

        return new AssignmentState(self::str($raw['v']), $pairs, $x);
    }

    /** @return array{v: string, a: list<array{string, string}>, x: list<string>} */
    private static function plain(AssignmentState $state): array
    {
        return ['v' => $state->v, 'a' => $state->a, 'x' => $state->x];
    }

    /** @return list<\stdClass> */
    private static function objectList(mixed $value): array
    {
        $out = [];
        foreach (self::arr($value) as $item) {
            self::assertInstanceOf(\stdClass::class, $item);
            $out[] = $item;
        }

        return $out;
    }
}
