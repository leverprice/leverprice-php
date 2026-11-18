<?php

declare(strict_types=1);

namespace Leverprice\Tests;

use Leverprice\Http\CurlTransport;
use Leverprice\Http\HttpRequest;
use Leverprice\Http\HttpResponse;
use Leverprice\Http\Psr18Transport;
use Leverprice\Http\StreamTransport;
use Leverprice\Http\Transport;
use Leverprice\Http\TransportException;
use Leverprice\Leverprice;
use Leverprice\Quote;
use Leverprice\State\MemoryStore;
use Leverprice\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    /** @var resource|null */
    private static $keepAlive = null;
    private static string $keepAliveBase = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Support/server.php'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;
        self::$base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 200; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$server, self::$keepAlive] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    /** @return iterable<string, array{Transport}> */
    public static function transports(): iterable
    {
        if (CurlTransport::isSupported()) {
            yield 'curl' => [new CurlTransport()];
        }
        yield 'stream' => [new StreamTransport()];
    }

    #[DataProvider('transports')]
    public function testSendsRequestsAndReadsResponses(Transport $transport): void
    {
        $this->requireServer();
        $get = $transport->send(new HttpRequest('GET', self::$base . '/v1/config', ['Authorization' => 'Bearer x', 'If-None-Match' => '"v1"', 'Leverprice-Products' => ''], null, 5000));
        self::assertSame(200, $get->status);
        self::assertTrue($get->ok());
        self::assertSame('"v9"', $get->header('etag'));
        self::assertSame('9', $get->header('Leverprice-Config-Version'));
        $echo = self::arr(json_decode($get->body, true, 512, JSON_THROW_ON_ERROR));
        $headers = self::arr($echo['headers']);
        self::assertSame('GET', $echo['method']);
        self::assertSame('Bearer x', $headers['authorization']);
        self::assertSame('"v1"', $headers['if-none-match']);
        self::assertSame('', $headers['leverprice-products'] ?? null);
        self::assertArrayNotHasKey('origin', $headers);

        $body = str_repeat('{"events":[]}', 200);
        $post = $transport->send(new HttpRequest('POST', self::$base . '/v1/events', ['Content-Type' => 'application/json'], $body, 5000));
        self::assertSame(202, $post->status);
        self::assertSame($body, self::arr(json_decode($post->body, true, 512, JSON_THROW_ON_ERROR))['body']);

        $missing = $transport->send(new HttpRequest('GET', self::$base . '/nope', [], null, 5000));
        self::assertSame(404, $missing->status);
        self::assertFalse($missing->ok());

        $redirect = $transport->send(new HttpRequest('GET', self::$base . '/redirect', [], null, 5000));
        self::assertSame(302, $redirect->status);
    }

    #[DataProvider('transports')]
    public function testDoesNotWaitForTheServerToCloseTheConnection(Transport $transport): void
    {
        $base = self::keepAliveServer();
        $start = microtime(true);
        $response = $transport->send(new HttpRequest('GET', $base . '/v1/config', [], null, 4000));
        self::assertSame([200, '{"ok":true}'], [$response->status, $response->body]);
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    #[DataProvider('transports')]
    public function testEnforcesTheTimeout(Transport $transport): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        $start = microtime(true);
        try {
            $transport->send(new HttpRequest('GET', 'http://' . $name . '/v1/config', [], null, 300));
            self::fail('no timeout');
        } catch (TransportException $e) {
            self::assertTrue($e->timedOut, $e->getMessage());
        } finally {
            fclose($socket);
        }
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    #[DataProvider('transports')]
    public function testReportsConnectionErrors(Transport $transport): void
    {
        $this->expectException(TransportException::class);
        $transport->send(new HttpRequest('GET', 'http://127.0.0.1:' . self::freePort() . '/v1/config', [], null, 2000));
    }

    #[DataProvider('transports')]
    public function testClientAgainstALocalServer(Transport $transport): void
    {
        $this->requireServer();
        $errors = [];
        $client = new Leverprice([
            'transport' => $transport,
            'secretKey' => self::SECRET_KEY,
            'products' => self::defaultGuards(),
            'apiUrl' => self::$base . '/',
            'cache' => new MemoryStore(),
            'registerShutdown' => false,
            'onError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        ]);
        self::assertSame(Quote::REASON_NO_CONFIG, $client->getPrice('pro', ['request' => ['user-agent' => self::UA]])->reason);
        self::assertSame(['Leverprice config: response is not a signed config'], $errors);
    }

    public function testPsr18Adapter(): void
    {
        $client = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if (str_contains((string) $request->getUri(), 'boom')) {
                    throw new \RuntimeException('cURL error 28: Operation timed out');
                }

                return new Response(202, ['Leverprice-Config-Version' => ['3', '4']], 'ok');
            }
        };
        $factory = new Psr17Factory();
        $transport = new Psr18Transport($client, $factory, $factory);

        $response = $transport->send(new HttpRequest('POST', 'https://api.test/v1/events', ['Content-Type' => 'application/json'], '{}', 1000));
        self::assertSame([202, '3, 4', 'ok'], [$response->status, $response->header('leverprice-config-version'), $response->body]);
        self::assertSame('{}', (string) $client->requests[0]->getBody());
        self::assertSame('application/json', $client->requests[0]->getHeaderLine('Content-Type'));

        try {
            $transport->send(new HttpRequest('GET', 'https://api.test/boom', [], null, 1000));
            self::fail('no exception');
        } catch (TransportException $e) {
            self::assertTrue($e->timedOut);
        }

        $lp = new Leverprice(['secretKey' => self::SECRET_KEY, 'products' => self::defaultGuards(), 'httpClient' => $client, 'requestFactory' => $factory, 'streamFactory' => $factory, 'cache' => false, 'registerShutdown' => false, 'onError' => static function (): void {}]);
        $lp->trackPurchase(['provider' => 'stripe', 'orderId' => 'pi_1', 'amount' => 1, 'currency' => 'usd']);
        $last = end($client->requests);
        self::assertInstanceOf(RequestInterface::class, $last);
        self::assertSame('leverprice-sdk-php/0.4.1', $last->getHeaderLine('User-Agent'));
    }

    public function testHttpResponseHeaders(): void
    {
        $response = new HttpResponse(204, ['ETag' => '"a"', 'X-Multi' => ['1', '2']]);
        self::assertSame('"a"', $response->header('etag'));
        self::assertSame('1, 2', $response->header('x-multi'));
        self::assertNull($response->header('missing'));
    }

    private function requireServer(): void
    {
        if (self::$base === '') {
            self::markTestSkipped('could not start the PHP built-in server');
        }
    }

    private static function keepAliveServer(): string
    {
        if (self::$keepAliveBase === '') {
            $command = [PHP_BINARY, __DIR__ . '/Support/keepalive.php'];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::nullDevice(), 'w']], $pipes);
            if (!is_resource($process)) {
                self::markTestSkipped('could not start the keep-alive server');
            }
            self::$keepAlive = $process;
            $address = trim((string) fgets($pipes[1]));
            if ($address === '') {
                self::markTestSkipped('the keep-alive server did not start');
            }
            self::$keepAliveBase = 'http://' . $address;
        }

        return self::$keepAliveBase;
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
