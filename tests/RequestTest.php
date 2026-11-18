<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Internal\RequestReader;
use Leverprice\Internal\Superglobals;
use Leverprice\Quote;
use Leverprice\Tests\Support\FakeClock;
use Leverprice\Tests\Support\TestCase;
use Nyholm\Psr7\Response as PsrResponse;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class RequestTest extends TestCase
{
    private const COOKIE = 'a=1; __pp=v.s; __pp_f=1';

    /** @var array<mixed> */
    private array $server = [];

    /** @var array<mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->cookies = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookies;
        Superglobals::$sapi = null;
    }

    public function testReadsSymfonyAndLaravelRequests(): void
    {
        $request = SymfonyRequest::create('https://shop.test/pricing', 'GET', [], [], [], [
            'HTTP_COOKIE' => self::COOKIE,
            'HTTP_USER_AGENT' => self::UA,
            'HTTP_SEC_PURPOSE' => 'prefetch',
        ]);
        $info = RequestReader::read(['request' => $request], '__pp');
        self::assertSame(self::COOKIE, $info->cookieInput);
        self::assertSame(['v.s'], $info->assignmentCookies);
        self::assertTrue($info->pinned);
        self::assertSame(self::UA, $info->userAgent);
        self::assertTrue($info->prefetch);
    }

    public function testReadsTurboHoverPrefetch(): void
    {
        $request = SymfonyRequest::create('https://shop.test/pricing', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => self::UA,
            'HTTP_X_SEC_PURPOSE' => 'prefetch',
        ]);
        self::assertTrue(RequestReader::read(['request' => $request], '__pp')->prefetch);

        $_SERVER['HTTP_X_SEC_PURPOSE'] = 'prefetch';
        self::assertTrue(RequestReader::read([], '__pp')->prefetch);
    }

    public function testReadsPsr7Requests(): void
    {
        $request = (new ServerRequest('GET', 'https://shop.test/'))
            ->withHeader('Cookie', 'a=1')
            ->withAddedHeader('Cookie', '__pp=v.s')
            ->withHeader('User-Agent', self::UA)
            ->withHeader('Next-Router-Prefetch', '1');
        $info = RequestReader::read(['request' => $request], '__pp');
        self::assertSame('a=1; __pp=v.s', $info->cookieInput);
        self::assertSame(['v.s'], $info->assignmentCookies);
        self::assertFalse($info->pinned);
        self::assertSame(self::UA, $info->userAgent);
        self::assertTrue($info->prefetch);
    }

    public function testReadsHeaderArraysCaseInsensitively(): void
    {
        self::assertSame('a=1', RequestReader::header(['Cookie' => 'a=1'], 'cookie'));
        self::assertSame(self::UA, RequestReader::header(['USER-AGENT' => self::UA], 'User-Agent'));
        self::assertSame('a=1; __pp=v.s', RequestReader::header(['cookie' => ['a=1', '__pp=v.s']], 'cookie'));
        self::assertSame('one, two', RequestReader::header(['x-list' => ['one', 'two']], 'x-list'));
        self::assertSame('a=1', RequestReader::header(['HTTP_COOKIE' => 'a=1'], 'cookie'));
        self::assertSame('a=1', RequestReader::header((object) ['headers' => ['cookie' => 'a=1']], 'cookie'));
        self::assertNull(RequestReader::header(['empty' => []], 'empty'));
        self::assertNull(RequestReader::header(['cookie' => null], 'cookie'));
        self::assertNull(RequestReader::header(null, 'cookie'));
        self::assertNull(RequestReader::header(new \stdClass(), 'cookie'));
        self::assertNull(RequestReader::header(new class {
            public function getHeader(string $name): never
            {
                throw new \RuntimeException('boom');
            }
        }, 'cookie'));
    }

    public function testExplicitCookieAndUserAgentWin(): void
    {
        $request = ['cookie' => '__pp=from-request', 'user-agent' => 'curl/8.7.1'];
        $info = RequestReader::read(['request' => $request, 'cookie' => '__pp=explicit', 'userAgent' => self::UA], '__pp');
        self::assertSame(['explicit'], $info->assignmentCookies);
        self::assertSame(self::UA, $info->userAgent);

        $fallback = RequestReader::read(['request' => $request, 'cookie' => null, 'userAgent' => null], '__pp');
        self::assertSame(['from-request'], $fallback->assignmentCookies);
        self::assertSame('curl/8.7.1', $fallback->userAgent);

        $legacy = RequestReader::read(['request' => [], 'cookie' => ' body.sig '], '__pp');
        self::assertSame(['body.sig'], $legacy->assignmentCookies);
        self::assertFalse($legacy->pinned);
    }

    public function testReadsTheCountryOnlyFromTheHeaderItIsToldToTrust(): void
    {
        $request = ['cf-ipcountry' => 'br', 'x-vercel-ip-country' => 'IN'];
        self::assertNull(RequestReader::read(['request' => $request], '__pp')->country);
        self::assertSame('BR', RequestReader::read(['request' => $request], '__pp', 'cf-ipcountry')->country);
        self::assertSame('IN', RequestReader::read(['request' => $request], '__pp', 'x-vercel-ip-country')->country);
        self::assertSame('DE', RequestReader::read(['request' => $request, 'country' => 'de'], '__pp', 'cf-ipcountry')->country);
        self::assertNull(RequestReader::read(['request' => $request, 'country' => 'nowhere'], '__pp', 'cf-ipcountry')->country);
        self::assertSame('BR', RequestReader::read(['request' => $request, 'country' => null], '__pp', 'cf-ipcountry')->country);
        self::assertNull(RequestReader::read(['request' => ['cf-ipcountry' => 'XX']], '__pp', 'cf-ipcountry')->country);
        self::assertNull(RequestReader::read(['request' => ['cf-ipcountry' => ['BR', 'IN']]], '__pp', 'cf-ipcountry')->country);

        $symfony = SymfonyRequest::create('https://shop.test/pricing', 'GET', [], [], [], ['HTTP_CF_IPCOUNTRY' => 'IN']);
        self::assertSame('IN', RequestReader::read(['request' => $symfony], '__pp', 'cf-ipcountry')->country);
        $psr = (new ServerRequest('GET', 'https://shop.test/'))->withHeader('CloudFront-Viewer-Country', 'DE');
        self::assertSame('DE', RequestReader::read(['request' => $psr], '__pp', 'cloudfront-viewer-country')->country);

        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'JP';
        self::assertSame('JP', RequestReader::read([], '__pp', 'cf-ipcountry')->country);
        self::assertNull(RequestReader::read([], '__pp')->country);
        self::assertNull(RequestReader::read([], '__pp', 'cloudfront-viewer-country')->country);
    }

    public function testTheCountryHeaderOptionOverridesTheProjectHeaderInEveryRequestSource(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'BR';
        $_SERVER['HTTP_X_GEO_COUNTRY'] = 'IN';
        unset($_SERVER['HTTP_COOKIE']);
        $_COOKIE = [];
        $sources = [
            'superglobals' => [],
            'symfony' => ['request' => SymfonyRequest::create('https://shop.test/pricing', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => self::UA,
                'HTTP_CF_IPCOUNTRY' => 'BR',
                'HTTP_X_GEO_COUNTRY' => 'IN',
            ])],
            'psr-7' => ['request' => (new ServerRequest('GET', 'https://shop.test/'))
                ->withHeader('User-Agent', self::UA)
                ->withHeader('CF-IPCountry', 'BR')
                ->withHeader('X-Geo-Country', 'IN')],
            'array' => ['request' => ['User-Agent' => self::UA, 'CF-IPCountry' => 'BR', 'X-Geo-Country' => 'IN']],
        ];
        [$project] = $this->vectorClient(new FakeClock(self::vectorNow()), null, ['localCurrencies' => true]);
        [$option] = $this->vectorClient(new FakeClock(self::vectorNow()), null, ['countryHeader' => 'CF-IPCountry', 'localCurrencies' => true]);

        foreach ($sources as $name => $context) {
            $quotes = [
                $project->getPrice('geo', $context),
                $option->getPrice('geo', $context),
                $option->getPrice('geo', $context + ['country' => 'mx']),
            ];
            self::assertSame(
                [['IN', 'inr', 99900], ['BR', 'usd', 2000], ['MX', 'usd', 2000]],
                array_map(static fn(Quote $q): array => [$q->country, $q->currency, $q->price], $quotes),
                $name,
            );
        }
    }

    public function testFallsBackToSuperglobals(): void
    {
        $_SERVER['HTTP_COOKIE'] = self::COOKIE;
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
        $_SERVER['HTTP_X_MOZ'] = 'prefetch';
        $info = RequestReader::read([], '__pp');
        self::assertSame(['v.s'], $info->assignmentCookies);
        self::assertTrue($info->pinned);
        self::assertSame(self::UA, $info->userAgent);
        self::assertTrue($info->prefetch);

        unset($_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_X_MOZ']);
        $_COOKIE = ['a' => 'x;y', '__pp' => 'v.s', 'nested' => ['k' => 'v']];
        $info = RequestReader::read([], '__pp');
        self::assertSame('a=x%3By; __pp=v.s', $info->cookieInput);
        self::assertSame(['v.s'], $info->assignmentCookies);
        self::assertFalse($info->prefetch);

        $_COOKIE = [];
        $empty = RequestReader::read([], '__pp');
        self::assertNull($empty->cookieInput);
        self::assertSame([], $empty->assignmentCookies);
        self::assertNull($empty->userAgent);
    }

    public function testSuperglobalQuotesShareOneVisitorPerRequest(): void
    {
        Superglobals::$sapi = 'fpm-fcgi';
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
        $_SERVER['REQUEST_TIME_FLOAT'] = 1800000000.5;
        unset($_SERVER['HTTP_COOKIE']);
        $_COOKIE = [];
        [$client] = $this->vectorClient(new FakeClock(self::vectorNow()));

        $first = $client->getPrice('pro');
        $second = $client->getPrice('pro');
        self::assertSame(Quote::REASON_EXPERIMENT, $first->reason);
        self::assertSame($first->visitorId, $second->visitorId);
        self::assertSame([], $second->setCookie);

        $_SERVER['REQUEST_TIME_FLOAT'] = 1800000001.5;
        self::assertNotSame($first->visitorId, $client->getPrice('pro')->visitorId);
    }

    public function testAppliesCookiesToResponses(): void
    {
        [$client] = $this->vectorClient(new FakeClock(self::vectorNow()));
        $quote = $client->getPrice('pro', ['request' => ['user-agent' => self::UA]]);
        self::assertCount(1, $quote->setCookie);

        $symfony = $quote->applyCookiesTo(new SymfonyResponse());
        self::assertInstanceOf(SymfonyResponse::class, $symfony);
        $cookies = $symfony->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('__pp', $cookies[0]->getName());
        self::assertSame(explode(';', substr($quote->setCookie[0], 5))[0], $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertTrue($cookies[0]->isSecure());
        self::assertSame('lax', $cookies[0]->getSameSite());

        $psr = $quote->applyCookiesTo(new PsrResponse());
        self::assertInstanceOf(PsrResponse::class, $psr);
        self::assertSame($quote->setCookie, $psr->getHeader('Set-Cookie'));

        $this->expectException(\InvalidArgumentException::class);
        $quote->applyCookiesTo(new \stdClass());
    }
}
