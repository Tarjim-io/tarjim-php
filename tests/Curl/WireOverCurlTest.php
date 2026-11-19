<?php

declare(strict_types=1);

namespace Tarjim\Tests\Curl;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Tests\Support\Route;
use Tarjim\Tests\Support\TestServer;
use Tarjim\Wire\CurlTransport;
use Tarjim\Wire\DeliveryClient;
use Tarjim\Wire\FileRef;
use Tarjim\Wire\Outcome;

/** The delivery client over real curl, against a local server playing the delivery API in origin mode. */
final class WireOverCurlTest extends TestCase
{
    /** @var TestServer */
    private $server;

    protected function setUp(): void
    {
        $this->server = new TestServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private static function release(): ReleaseBuilder
    {
        return (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello'])
            ->flat('ns1', 'namespace', 'default', 'ar', ['hello' => 'مرحبا']);
    }

    /** @return array{0: Outcome, 1: Outcome, 2: \Tarjim\Wire\FilesResult} */
    private function readAll(string $deliveryUrl, ReleaseBuilder $release): array
    {
        $client = new DeliveryClient(new CurlTransport(), $deliveryUrl, ReleaseBuilder::KEY);
        $meta = $client->fetchMeta();
        self::assertSame(Outcome::RECEIVED, $meta->kind);
        self::assertNotNull($meta->meta);
        $manifest = $client->fetchManifest($meta->meta);
        $refs = [];
        foreach ($release->files as $hash => $bytes) {
            $refs[] = new FileRef($hash, strlen($bytes));
        }

        return [$meta, $manifest, $client->fetchFiles($meta->meta, $refs)];
    }

    public function testReadsAndVerifiesAWholeRelease(): void
    {
        $release = self::release();
        [, $manifest, $files] = $this->readAll($release->serveOrigin($this->server), $release);

        self::assertSame(Outcome::VERIFIED, $manifest->kind);
        foreach ($files->results as $outcome) {
            self::assertSame(Outcome::VERIFIED, $outcome->kind);
        }
    }

    public function testVerifiesGzipEncodedAnswersOnTheDecodedBytes(): void
    {
        $release = self::release();
        $url = $release->serveOrigin($this->server);
        $base = '/delivery/dst_test/';
        $this->server->route($base . 'released/7/manifest', Route::answer(200, $release->manifestBytes())->gzip());
        foreach ($release->files as $hash => $bytes) {
            $this->server->route($base . 'released/slices/' . $hash . '.json', Route::answer(200, $bytes)->gzip());
        }
        [, $manifest, $files] = $this->readAll($url, $release);

        self::assertSame(Outcome::VERIFIED, $manifest->kind);
        foreach ($files->results as $outcome) {
            self::assertSame(Outcome::VERIFIED, $outcome->kind);
        }
    }

    public function testTheKeyNeverReachesAnotherHost(): void
    {
        $release = self::release();
        $url = $release->serveOrigin($this->server);
        // Same server, other host name: a different origin as far as the client must care.
        $other = $this->server->origin('localhost') . '/delivery/dst_test/';
        $this->server->route('/delivery/dst_test/meta', Route::json(200, $release->meta([
            'manifestUrl' => $other . 'released/7/manifest',
            'slicesBaseUrl' => $other . 'released/slices/',
        ])));
        [, $manifest, $files] = $this->readAll($url, $release);

        self::assertSame(Outcome::REFUSED, $manifest->kind);
        foreach ($this->server->requests() as $request) {
            $host = (string) TestServer::headerOf($request, 'host');
            if (stripos($host, 'localhost') === 0) {
                self::assertNull(TestServer::headerOf($request, 'x-tarjim-apikey'), 'key sent to ' . $host);
            }
        }
        self::assertSame([], array_filter($this->server->requests(), static function (array $r): bool {
            return stripos((string) TestServer::headerOf($r, 'host'), 'localhost') === 0;
        }));
    }

    public function testTheKeyIsNeverInARequestLine(): void
    {
        $release = self::release();
        $this->readAll($release->serveOrigin($this->server), $release);

        self::assertNotEmpty($this->server->requests());
        foreach ($this->server->requests() as $request) {
            self::assertStringNotContainsString(ReleaseBuilder::KEY, $request['path'] . '?' . $request['query']);
        }
    }

    public function testARedirectedMetaIsNotFollowed(): void
    {
        $release = self::release();
        $release->serveOrigin($this->server, '/real/meta');
        $this->server->route('/delivery/dst_test/meta', Route::redirect(302, $this->server->origin() . '/real/meta'));
        $client = new DeliveryClient(new CurlTransport(), $this->server->origin() . '/delivery/dst_test/meta', ReleaseBuilder::KEY);
        $outcome = $client->fetchMeta();

        self::assertSame(Outcome::NETWORK, $outcome->kind);
        self::assertSame([], array_filter($this->server->requests(), static function (array $r): bool {
            return $r['path'] === '/real/meta';
        }));
    }
}
