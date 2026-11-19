<?php

declare(strict_types=1);

namespace Tarjim\Tests\Curl;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\Route;
use Tarjim\Tests\Support\TestServer;

/**
 * Checks the local server itself with plain curl, so a failure in the transport's own tests is
 * the transport's and not the harness's.
 */
final class TestServerTest extends TestCase
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

    /**
     * @param array<int, mixed> $options
     * @return array{status: int, body: string, headers: string, seconds: float}
     */
    private static function get(string $url, array $options = []): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
        $started = microtime(true);
        $raw = (string) curl_exec($curl);
        $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $result = [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'headers' => substr($raw, 0, $size),
            'body' => substr($raw, $size),
            'seconds' => microtime(true) - $started,
        ];
        curl_close($curl);

        return $result;
    }

    public function testServesAGzipEncodedBody(): void
    {
        self::$server->route('/gz', Route::answer(200, 'hello hello hello')->gzip());
        $raw = self::get(self::$server->origin() . '/gz');
        $decoded = self::get(self::$server->origin() . '/gz', [CURLOPT_ENCODING => '']);

        self::assertStringContainsStringIgnoringCase('content-encoding: gzip', $raw['headers']);
        self::assertNotSame('hello hello hello', $raw['body']);
        self::assertSame('hello hello hello', $decoded['body']);
    }

    public function testAnswersARedirectThatCurlNeedNotFollow(): void
    {
        self::$server->route('/moved', Route::redirect(302, self::$server->origin('localhost') . '/elsewhere'));
        $answer = self::get(self::$server->origin() . '/moved');

        self::assertSame(302, $answer['status']);
        self::assertStringContainsString('/elsewhere', $answer['headers']);
    }

    public function testStallsMidBody(): void
    {
        self::$server->route('/stall', Route::answer(200, str_repeat('x', 100))->stall(10, 2));
        $answer = self::get(self::$server->origin() . '/stall');

        self::assertSame(100, strlen($answer['body']));
        self::assertGreaterThanOrEqual(1.8, $answer['seconds']);
    }

    public function testStreamsALargeBody(): void
    {
        self::$server->route('/big', Route::answer(200, str_repeat('y', 1024))->repeat(3 * 1024));
        self::assertSame(3 * 1024 * 1024, strlen(self::get(self::$server->origin() . '/big')['body']));
    }

    public function testSendsHeaderNamesInTheCaseGiven(): void
    {
        self::$server->route('/case', Route::answer(429, '', ['ReTrY-AfTeR' => '7']));
        self::assertStringContainsString('ReTrY-AfTeR: 7', self::get(self::$server->origin() . '/case')['headers']);
    }

    public function testLogsEachRequestWithItsHeaders(): void
    {
        self::get(self::$server->origin() . '/logged?a=1', [CURLOPT_HTTPHEADER => ['X-Probe: yes']]);
        $logged = array_values(array_filter(self::$server->requests(), static function (array $r): bool {
            return $r['path'] === '/logged';
        }));

        self::assertCount(1, $logged);
        self::assertSame('a=1', $logged[0]['query']);
        self::assertSame('yes', TestServer::headerOf($logged[0], 'x-probe'));
    }
}
