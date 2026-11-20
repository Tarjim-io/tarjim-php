<?php

declare(strict_types=1);

namespace Tarjim\Tests\Curl;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\Route;
use Tarjim\Tests\Support\TestServer;
use Tarjim\Wire\CurlTransport;
use Tarjim\Wire\Request;

final class CurlTransportTest extends TestCase
{
    /** @var TestServer */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private static function send(string $path, int $limit = 1048576, array $headers = [], ?CurlTransport $transport = null): \Tarjim\Wire\Response
    {
        return ($transport ?? new CurlTransport())->send(new Request(self::$server->origin() . $path, $headers, $limit));
    }

    /** @return list<array<string, mixed>> */
    private static function requestsTo(string $path): array
    {
        return array_values(array_filter(self::$server->requests(), static function (array $r) use ($path): bool {
            return $r['path'] === $path;
        }));
    }

    public function testReturnsStatusBodyAndHeadersWithLowercasedNames(): void
    {
        self::$server->route('/plain', Route::answer(200, '{"a":1}', ['Content-Type' => 'application/json', 'ETag' => '"r7-x"']));
        $response = self::send('/plain');

        self::assertSame(200, $response->status);
        self::assertSame('{"a":1}', $response->body);
        self::assertSame('"r7-x"', $response->headers['etag'] ?? null);
        self::assertFalse($response->tooLarge);
    }

    public function testReadsAHeaderWhateverTheCaseItArrivesIn(): void
    {
        self::$server->route('/case', Route::answer(429, '', ['ReTrY-AfTeR' => '7']));
        $response = self::send('/case');

        self::assertSame(429, $response->status);
        self::assertSame('7', $response->headers['retry-after'] ?? null);
        self::assertSame('7', $response->header('Retry-After'));
    }

    public function testReadsTheBodyOfAnErrorAnswer(): void
    {
        self::$server->route('/problem', Route::json(404, ['code' => 'delivery.stage_unreleased']));
        $response = self::send('/problem');

        self::assertSame(404, $response->status);
        self::assertSame('{"code":"delivery.stage_unreleased"}', $response->body);
    }

    public function testSendsTheGivenHeaders(): void
    {
        self::$server->route('/headers', Route::answer(200, 'ok'));
        self::send('/headers', 100, ['X-Tarjim-Apikey' => 'tj_dev_probe', 'X-Tarjim-Api-Version' => '2026-07-29']);
        $logged = self::requestsTo('/headers');

        self::assertSame('tj_dev_probe', TestServer::headerOf($logged[0], 'x-tarjim-apikey'));
        self::assertSame('2026-07-29', TestServer::headerOf($logged[0], 'x-tarjim-api-version'));
    }

    public function testDecodesAGzipBodyAndReturnsTheDecodedBytes(): void
    {
        $bytes = (string) json_encode(['keys' => array_fill(0, 200, 'repeated text compresses')]);
        self::$server->route('/gz', Route::answer(200, $bytes, ['Content-Type' => 'application/json'])->gzip());
        $response = self::send('/gz', strlen($bytes));

        self::assertSame(200, $response->status);
        self::assertSame(hash('sha256', $bytes), hash('sha256', (string) $response->body));
    }

    public function testAsksForCompressedBodies(): void
    {
        self::$server->route('/accept', Route::answer(200, 'ok'));
        self::send('/accept');

        self::assertStringContainsString('gzip', (string) TestServer::headerOf(self::requestsTo('/accept')[0], 'accept-encoding'));
    }

    /** @return iterable<string, array{int}> */
    public function redirects(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider redirects */
    public function testNeverFollowsARedirectAndReportsNoAnswer(int $status): void
    {
        $target = '/redirected-' . $status;
        self::$server->route('/moved-' . $status, Route::redirect($status, self::$server->origin('localhost') . $target));
        self::$server->route($target, Route::answer(200, 'followed'));
        $response = self::send('/moved-' . $status, 100, ['X-Tarjim-Apikey' => 'tj_dev_probe']);

        self::assertSame(0, $response->status);
        self::assertNull($response->body);
        self::assertSame([], self::requestsTo($target));
    }

    public function testPassesANotModifiedAnswerThrough(): void
    {
        self::$server->route('/same', Route::answer(304, '', ['ETag' => '"r7-x"']));

        self::assertSame(304, self::send('/same')->status);
    }

    public function testStopsReadingABodyOverTheLimit(): void
    {
        $server = new TestServer();
        // Past the limit the server goes quiet for 10 s: a transport that read to the end would wait.
        $server->route('/big', Route::answer(200, str_repeat('z', 64 * 1024))->repeat(32)->stall(2 * 1024 * 1024, 10));
        $started = microtime(true);
        $response = (new CurlTransport())->send(new Request($server->origin() . '/big', [], 1024 * 1024));
        $elapsed = microtime(true) - $started;
        $server->stop();

        self::assertTrue($response->tooLarge);
        self::assertNull($response->body);
        self::assertLessThan(5, $elapsed);
    }

    public function testCountsTheLimitInDecodedBytes(): void
    {
        $server = new TestServer();
        // About 20 KB on the wire, 16 MiB once decoded.
        $server->route('/bomb', Route::answer(200, str_repeat('a', 1024 * 1024))->repeat(16)->gzip());
        $started = microtime(true);
        $response = (new CurlTransport())->send(new Request($server->origin() . '/bomb', [], 1024 * 1024));
        $elapsed = microtime(true) - $started;
        $server->stop();

        self::assertTrue($response->tooLarge);
        self::assertNull($response->body);
        self::assertLessThan(5, $elapsed);
    }

    public function testABodyExactlyAtTheLimitIsRead(): void
    {
        self::$server->route('/exact', Route::answer(200, str_repeat('q', 1000)));
        $response = self::send('/exact', 1000);

        self::assertFalse($response->tooLarge);
        self::assertSame(1000, strlen((string) $response->body));
    }

    public function testGivesUpOnAStalledAnswer(): void
    {
        $server = new TestServer();
        $server->route('/stall', Route::answer(200, str_repeat('s', 100))->stall(10, 8));
        $started = microtime(true);
        $response = (new CurlTransport(5, 2))->send(new Request($server->origin() . '/stall', [], 1000));
        $elapsed = microtime(true) - $started;
        $server->stop();

        self::assertSame(0, $response->status);
        self::assertNull($response->body);
        self::assertLessThan(6, $elapsed);
    }

    public function testASlowButSteadyAnswerIsNotAStall(): void
    {
        $server = new TestServer();
        $server->route('/slow', Route::answer(200, str_repeat('s', 100))->stall(50, 1));
        $response = (new CurlTransport(5, 3))->send(new Request($server->origin() . '/slow', [], 1000));
        $server->stop();

        self::assertSame(200, $response->status);
        self::assertSame(100, strlen((string) $response->body));
    }

    public function testNothingListeningIsNoAnswer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $response = (new CurlTransport())->send(new Request('http://' . $name . '/meta', [], 100));

        self::assertSame(0, $response->status);
    }

    /** @return iterable<string, array{string}> */
    public function otherSchemes(): iterable
    {
        yield 'file' => ['file:///etc/hosts'];
        yield 'ftp' => ['ftp://127.0.0.1/x'];
        yield 'gopher' => ['gopher://127.0.0.1/x'];
        yield 'dict' => ['dict://127.0.0.1/x'];
    }

    /** @dataProvider otherSchemes */
    public function testOnlySpeaksHttp(string $url): void
    {
        $response = (new CurlTransport())->send(new Request($url, [], 100000));

        self::assertSame(0, $response->status);
        self::assertNull($response->body);
    }

    public function testRefusesAHeaderValueWithALineBreak(): void
    {
        self::$server->route('/inject', Route::answer(200, 'ok'));
        $response = self::send('/inject', 100, ['X-Tarjim-Apikey' => "tj_dev_probe\r\nX-Injected: 1"]);
        $logged = self::requestsTo('/inject');

        self::assertSame(0, $response->status);
        foreach ($logged as $request) {
            self::assertNull(TestServer::headerOf($request, 'x-injected'));
        }
    }

    public function testARedirectWithABodyOverTheLimitIsStillNoAnswer(): void
    {
        self::$server->route('/moved-big', Route::answer(302, str_repeat('r', 4096), ['Location' => '/elsewhere'])->repeat(64));
        $response = self::send('/moved-big', 1024);

        self::assertSame(0, $response->status);
        self::assertNull($response->body);
        self::assertFalse($response->tooLarge);
    }
}
