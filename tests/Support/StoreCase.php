<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tarjim\Client;
use Tarjim\RefreshResult;

/** A client over a fresh store directory, a fake transport and a fake clock. */
abstract class StoreCase extends TestCase
{
    protected const DELIVERY = 'https://delivery.example.test/delivery/dst_test/meta';
    protected const BASE = 'https://delivery.example.test/delivery/dst_test/';
    protected const KEY = ReleaseBuilder::KEY;

    /** @var string */
    protected $root;

    /** @var string */
    protected $dir;

    /** @var FakeTransport */
    protected $fake;

    /** @var FakeClock */
    protected $clock;

    /** @var list<array{string, string, array<string, mixed>}> */
    protected $logged = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tarjim-store-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
        $this->root = (string) realpath($this->root);
        $this->dir = $this->root . '/store';
        $this->fake = new FakeTransport();
        $this->clock = new FakeClock();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /** @return array<string, mixed> */
    protected function config(array $overrides = []): array
    {
        return $overrides + [
            'dir' => $this->dir,
            'deliveryUrl' => self::DELIVERY,
            'key' => self::KEY,
            'transport' => $this->fake,
            'now' => $this->clock->nowFn(),
            'sleep' => $this->clock->sleepFn(),
            'logger' => function (string $level, string $message, array $context): void {
                $this->logged[] = [$level, $message, $context];
            },
        ];
    }

    protected function client(array $overrides = []): Client
    {
        return Client::create($this->config($overrides));
    }

    protected function refresh(array $options = [], array $overrides = []): RefreshResult
    {
        return $this->client($overrides)->refresh($options);
    }

    /** A release with two languages of one flat bundle. */
    protected function release(string $id = '7', array $en = ['hello' => 'Hello'], array $fr = ['hello' => 'Bonjour']): ReleaseBuilder
    {
        return (new ReleaseBuilder($id))
            ->flat('ns1', 'namespace', 'default', 'en', $en)
            ->flat('ns1', 'namespace', 'default', 'fr', $fr);
    }

    /** Serves $release in origin mode through the fake transport, replacing whatever was served. */
    protected function serve(ReleaseBuilder $release, array $metaOverrides = [], ?string $etag = null): void
    {
        $this->fake->on(self::DELIVERY, ReleaseBuilder::metaResponse(
            $release->meta($metaOverrides),
            $etag ?? '"r' . $release->releaseId . '-' . $release->checksum() . '"'
        ));
        $this->fake->on(self::BASE . 'released/' . $release->releaseId . '/manifest', new \Tarjim\Wire\Response(200, [], $release->manifestBytes()));
        foreach ($release->files as $hash => $bytes) {
            $this->fake->on(self::BASE . 'released/slices/' . $hash . '.json', new \Tarjim\Wire\Response(200, [], $bytes));
        }
    }

    /** @return list<string> URLs requested since the last call, without the query */
    protected function takeRequests(): array
    {
        $urls = array_map(static function (string $url): string {
            return (string) strtok($url, '?');
        }, $this->fake->urls());
        $this->fake->requests = [];

        return $urls;
    }

    /** @return array<string, string> every file under $dir, relative path => bytes */
    protected function storeFiles(?string $dir = null): array
    {
        $dir = $dir ?? $this->dir;
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /** Puts the store's view of "now" past the check floor. */
    protected function laterBy(int $seconds): void
    {
        $this->clock->advance($seconds);
    }
}
