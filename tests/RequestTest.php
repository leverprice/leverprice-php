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
