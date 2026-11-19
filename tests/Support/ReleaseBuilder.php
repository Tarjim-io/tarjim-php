<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

use Tarjim\Wire\Response;

/**
 * A synthetic release: meta, manifest and slice files with hashes that agree, served in origin or
 * CDN mode. Contains no real customer text and no real key.
 */
final class ReleaseBuilder
{
    public const KEY = 'tj_dev_test-key-not-real-0000000000';

    /** @var string */
    public $releaseId;

    /** @var array<string, array<string, mixed>> */
    private $bundles = [];

    /** @var array<string, array<string, array{hash: string, size: int}>> */
    private $slices = [];

    /** @var array<string, string> hash => bytes */
    public $files = [];

    /** @var int */
    public $schemaVersion = 1;

    public function __construct(string $releaseId = '7')
    {
        $this->releaseId = $releaseId;
    }

    /** @param array<string, string> $keys name => text */
    public function flat(string $bundleId, string $type, string $name, string $locale, array $keys): self
    {
        $leaves = [];
        $id = 100;
        foreach ($keys as $key => $value) {
            $leaves[$key] = ['id' => (string) $id++, 'value' => $value];
        }

        return $this->slice($bundleId, ['type' => $type, 'name' => $name, 'layout' => 'flat', 'namespaces' => []], $locale, [
            'schemaVersion' => 1,
            'bundle' => $bundleId,
            'locale' => $locale,
            'keys' => $leaves,
        ]);
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $content
     */
    public function slice(string $bundleId, array $bundle, string $locale, array $content): self
    {
        $bytes = (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $bytes);
        $this->bundles[$bundleId] = $bundle;
        $this->slices[$bundleId][$locale] = ['hash' => $hash, 'size' => strlen($bytes)];
        $this->files[$hash] = $bytes;

        return $this;
    }

    public function manifestBytes(): string
    {
        $slices = [];
        foreach ($this->slices as $id => $locales) {
            foreach ($locales as $locale => $file) {
                $slices[$id][$locale] = ['json' => $file + ['transferSize' => $file['size']]];
            }
        }

        return (string) json_encode([
            'schemaVersion' => $this->schemaVersion,
            'baseLocale' => 'en',
            'bundles' => $this->bundles,
            'slices' => $slices,
            'bundleOrder' => array_keys($this->bundles),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function checksum(): string
    {
        return hash('sha256', $this->manifestBytes());
    }

    /**
     * The meta body. Origin mode uses the server's relative paths; CDN mode needs an absolute
     * https base and a signed query.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function meta(array $overrides = []): array
    {
        return $overrides + [
            'schemaVersion' => 1,
            'checksum' => $this->checksum(),
            'manifestUrl' => 'released/' . $this->releaseId . '/manifest',
            'slicesBaseUrl' => 'released/slices/',
            'authenticated' => true,
            'distribution' => 'dst_test',
            'stage' => 'dev',
            'releaseId' => $this->releaseId,
        ];
    }

    /** @param array<string, mixed> $meta */
    public static function metaResponse(array $meta, string $etag = '"r7-test"'): Response
    {
        return new Response(200, ['content-type' => 'application/json', 'etag' => $etag], (string) json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Serve this release in origin mode on a test server whose delivery URL path is $metaPath.
     */
    public function serveOrigin(TestServer $server, string $metaPath = '/delivery/dst_test/meta'): string
    {
        $base = substr($metaPath, 0, (int) strrpos($metaPath, '/') + 1);
        $server->route($metaPath, Route::json(200, $this->meta(), ['ETag' => '"r' . $this->releaseId . '-' . $this->checksum() . '"']));
        $server->route($base . 'released/' . $this->releaseId . '/manifest', Route::answer(200, $this->manifestBytes(), ['Content-Type' => 'application/json']));
        foreach ($this->files as $hash => $bytes) {
            $server->route($base . 'released/slices/' . $hash . '.json', Route::answer(200, $bytes, ['Content-Type' => 'application/json']));
        }

        return $server->origin() . $metaPath;
    }
}
