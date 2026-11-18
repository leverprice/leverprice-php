<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Bots;
use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Internal\Acceptor;
use Leverprice\Internal\AssignmentState;
use Leverprice\Internal\ConfigError;
use Leverprice\Internal\Cookie;
use Leverprice\Internal\RequestReader;
use Leverprice\Internal\Superglobals;
use Leverprice\Internal\TrustChain;
use Leverprice\Internal\Util;
use Leverprice\Internal\Wire;
use Leverprice\Leverprice;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\TestCase;
use Leverprice\Tests\Support\Vectors;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
            $refusal = null;
            try {
                $verified = $acceptor->accept($step->envelope, $now);
                $outcome = 'accept';
            } catch (ConfigError $e) {
                $verified = null;
                $outcome = 'reject';
                $refusal = $e->refusal;
            }
            self::assertSame($step->expect, $outcome, sprintf('step %d', $i));
            // What the next config request reports in Leverprice-Config-Error.
            self::assertSame($step->code ?? null, $refusal, sprintf('step %d', $i));
            if ($verified !== null) {
                self::assertSame($step->version, $verified->config->version);
                self::assertSame($step->dropped, $verified->dropped);
                if (property_exists($step, 'countryHeader')) {
                    self::assertSame($step->countryHeader, $verified->config->countryHeader, sprintf('step %d', $i));
                }
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

    public function testRefusesCountryHeadersThatVisitorsSend(): void
    {
        foreach (self::arr(Vectors::arrays()['countryHeaderNames']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['allowed'], !RequestReader::visitorSetHeader(self::str($case['name'])), self::str($case['name']));
        }
    }

    public function testNormalizesAddresses(): void
    {
        foreach (self::arr(Vectors::arrays()['addresses']) as $case) {
            $case = self::arr($case);
            self::assertSame($case['address'], RequestReader::normalizeAddress($case['value']), self::envelope($case['value']));
        }
    }

    public function testFindsTheClientAddressInEveryRequestShape(): void
    {
        $server = $_SERVER;
        try {
            foreach (self::arr(Vectors::arrays()['clientAddresses']) as $case) {
                $case = self::arr($case);
                /** @var array<string, string> $headers */
                $headers = self::arr($case['headers']);
                $remote = isset($case['remoteAddress']) ? ['REMOTE_ADDR' => self::str($case['remoteAddress'])] : [];
                $cgi = $remote;
                foreach ($headers as $name => $value) {
                    $cgi['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
                }
                $_SERVER = $cgi;
                $shapes = [
                    'array' => $headers + $remote,
                    'psr-7' => new ServerRequest('GET', 'https://shop.test/', $headers, null, '1.1', $remote),
                    'symfony' => new SymfonyRequest([], [], [], [], [], $cgi),
                    'superglobals' => Superglobals::instance(),
                ];
                foreach ($shapes as $shape => $request) {
                    self::assertSame($case['address'], RequestReader::clientAddress($request), $shape . ' ' . self::envelope($case));
                }
            }
        } finally {
            $_SERVER = $server;
        }
    }

    public function testDerivesACookielessVisitorsKey(): void
    {
        $key = self::assignmentKey();
        foreach (self::arr(Vectors::arrays()['anonVisitors']) as $case) {
            $case = self::arr($case);
            $message = 'pp:anon:v1:' . self::str($case['address']) . "\n" . self::str($case['userAgent']) . "\n" . self::str($case['acceptLanguage']);
            self::assertSame($case['message'], $message);
            self::assertSame($case['v'], Util::hmacHex($key, $message, 32));
        }
    }

    public function testNamesItsProductsOnGetConfig(): void
    {
        foreach (self::arr(Vectors::arrays()['productsHeader']) as $case) {
            $case = self::arr($case);
            $sent = null;
            [$client] = $this->vectorClient(new FakeClock(self::vectorNow()), static function (HttpRequest $request) use (&$sent): HttpResponse {
                $sent = $request->headers[Wire::PRODUCTS_HEADER] ?? null;

                return new HttpResponse(503, [], '{}');
            }, ['products' => self::arr($case['products'])]);
            $client->refresh();
            self::assertSame($case['header'], $sent);
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function countryHoldCases(): iterable
    {
        foreach (self::arr(self::arr(Vectors::arrays()['countryHold'])['cases']) as $case) {
            $case = self::arr($case);
            yield self::str($case['name']) => [$case];
        }
    }

    /** @param array<array-key, mixed> $case */
    #[DataProvider('countryHoldCases')]
    public function testCountryHold(array $case): void
    {
        $hold = self::arr(Vectors::arrays()['countryHold']);
        $key = self::assignmentKey();
        $clock = new FakeClock(self::vectorNow());
        $now = intdiv($clock->now, 1000);
        $headers = ['user-agent' => 'Mozilla/5.0 Chrome/131.0'];
        if (isset($case['country'])) {
            $headers['x-geo-country'] = self::str($case['country']);
        }
        $t = null;
        if ($case['cookie'] !== null) {
            $cookie = self::arr($case['cookie']);
            $t = isset($cookie['age']) ? $now - self::int($cookie['age']) : null;
            $state = self::state(['v' => $hold['visitor'], 'a' => $cookie['a'] ?? [], 'x' => $cookie['x'] ?? []]);
            if (isset($cookie['c'])) {
                $state->c = self::str($cookie['c']);
                $state->t = $t;
            }
            $headers['cookie'] = '__pp=' . Cookie::sign($key, $state);
        }
        $options = ['products' => self::arr($hold['guards'])];
        if (($case['localCurrencies'] ?? false) === true) {
            $options['localCurrencies'] = true;
        }
        [$client, $transport] = $this->vectorClient($clock, self::vectorApi(self::envelope($hold['config'])), $options);

        $quote = $client->getPrice(self::str($case['productKey']), ['request' => $headers]);
        $client->flush();

        $back = null;
        foreach ($quote->setCookie as $header) {
            if (str_starts_with($header, '__pp=')) {
                $back = Cookie::verify($key, explode(';', substr($header, 5))[0]);
                self::assertNotNull($back);
            }
        }
        $actual = [
            'price' => $quote->price,
            'currency' => $quote->currency,
            'country' => $quote->country,
            'regional' => $quote->regional,
            'reason' => $quote->reason,
            'variantId' => $quote->variantId,
            'exposures' => array_map(static function (array $event): array {
                unset($event['timestamp'], $event['visitorId']);

                return $event;
            }, $transport->sentEvents()),
            'cookie' => $back === null ? null : [
                'c' => $back->c,
                't' => $back->t === null ? null : ($back->t === $t ? 'kept' : 'fresh'),
                'a' => $back->a,
            ],
        ];
        if ($back !== null && $back->t !== null && $back->t !== $t) {
            self::assertSame($now, $back->t);
        }
        self::assertSame(self::arr($case['expect']), $actual);
        self::assertSame([], $this->errors);
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
        $c = $raw['c'] ?? null;
        $t = $raw['t'] ?? null;

        return new AssignmentState(self::str($raw['v']), $pairs, $x, is_string($c) ? $c : null, is_int($t) ? $t : null);
    }

    /** @return array<string, mixed> */
    private static function plain(AssignmentState $state): array
    {
        $plain = ['v' => $state->v, 'a' => $state->a, 'x' => $state->x];

        return $state->c === null ? $plain : $plain + ['c' => $state->c, 't' => $state->t];
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
