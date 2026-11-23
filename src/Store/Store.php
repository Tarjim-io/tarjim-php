<?php

declare(strict_types=1);

namespace Tarjim\Store;

/**
 * The local copy: objects/<sha256>.json, releases/<id>-<checksum8>.json, current.json, state.json,
 * dropped.json and lock. Every file name is built from a 64-hex hash, digits and 8 hex characters, never from
 * anything a response names (a bundle, a language).
 */
final class Store
{
    private const OBJECT_NAME = '/^[0-9a-f]{64}\.json\z/';
    private const INDEX_NAME = '/^[0-9]+-[0-9a-f]{8}\.json\z/';
    // Only what LocalDisk::put names its temp files: the folder may hold other programs' files.
    private const TEMP_NAME = '/^\.([0-9a-f]{64}\.json|[0-9]+-[0-9a-f]{8}\.json|current\.json|state\.json|dropped\.json)\.[0-9a-f]{8}\.tmp\z/';
    private const KEEP_SECONDS = 86400;

    /** @var Disk */
    private $disk;

    /** @var string */
    private $dir;

    public function __construct(Disk $disk, string $dir)
    {
        $this->disk = $disk;
        $this->dir = rtrim($dir, '/\\');
    }

    public static function isHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}\z/', $value) === 1;
    }

    public static function indexName(string $releaseId, string $checksum): string
    {
        return $releaseId . '-' . substr($checksum, 0, 8) . '.json';
    }

    public function lockPath(): string
    {
        return $this->dir . '/lock';
    }

    /** @return string|null what is wrong with the folder; null when it can be written */
    public function prepare(): ?string
    {
        foreach ([$this->dir, $this->dir . '/objects', $this->dir . '/releases'] as $path) {
            if (!$this->disk->ensureDir($path)) {
                return 'the store folder cannot be created';
            }
            // is_writable() is about this process's user, which is the point: a copy written by
            // another user (root at build time) is one this process could never update.
            if (!@is_dir($path) || !@is_writable($path)) {
                return 'the store folder is not writable by this user';
            }
        }

        return null;
    }

    public function readState(): State
    {
        return State::fromDecoded($this->readJson($this->dir . '/state.json'));
    }

    public function writeState(State $state): bool
    {
        return $this->putJson($this->dir . '/state.json', $state->toArray());
    }

    /** The installed release, or null when there is none or any file it names is missing. */
    public function held(): ?HeldCopy
    {
        $current = $this->readJson($this->dir . '/current.json');
        if ($current === null) {
            return null;
        }
        $index = $current['release'] ?? null;
        $releaseId = $current['releaseId'] ?? null;
        $checksum = $current['checksum'] ?? null;
        if (!is_string($index) || preg_match(self::INDEX_NAME, $index) !== 1
            || !is_string($releaseId) || preg_match('/^[0-9]+\z/', $releaseId) !== 1
            || !is_string($checksum) || !self::isHash($checksum)) {
            return null;
        }
        $hashes = $this->indexHashes($index);
        if ($hashes === null) {
            return null;
        }
        foreach ($hashes as $hash) {
            if ($this->disk->mtime($this->objectPath($hash)) === null) {
                return null;
            }
        }
        $distribution = $current['distribution'] ?? null;
        $stage = $current['stage'] ?? null;
        $installedAt = $current['installedAt'] ?? null;
        $previous = $current['previous'] ?? null;

        return new HeldCopy(
            $releaseId,
            $checksum,
            is_string($distribution) ? $distribution : null,
            is_string($stage) ? $stage : null,
            is_int($installedAt) ? $installedAt : null,
            $index,
            is_string($previous) && preg_match(self::INDEX_NAME, $previous) === 1 ? $previous : null
        );
    }

    /** The release index file current.json names, without checking the files behind it. */
    public function pointerName(): ?string
    {
        $current = $this->readJson($this->dir . '/current.json');
        $index = $current['release'] ?? null;

        return is_string($index) && preg_match(self::INDEX_NAME, $index) === 1 ? $index : null;
    }

    /**
     * The installed release's bundles for reading: the index file's name and, per bundle id, its
     * type, name, layout and language => object hash. Every bundle of the release is listed, flat
     * or not, so a name resolves the same way here as when it was installed; only a flat one has
     * languages. Lenient: a bundle or language that is not understood is dropped. Null when there
     * is no installed release or its index is unusable.
     *
     * @return array{name: string, bundles: array<string, array{type: string, name: string, layout: string|null, languages: array<string, string>}>}|null
     */
    public function currentIndex(): ?array
    {
        $name = $this->pointerName();
        if ($name === null) {
            return null;
        }
        $index = $this->readJson($this->dir . '/releases/' . $name);
        $raw = $index['bundles'] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        $bundles = [];
        foreach ($raw as $id => $bundle) {
            $type = is_array($bundle) ? ($bundle['type'] ?? null) : null;
            $bundleName = is_array($bundle) ? ($bundle['name'] ?? null) : null;
            $files = is_array($bundle) ? ($bundle['languages'] ?? null) : null;
            if (!is_string($type) || !is_string($bundleName) || !is_array($files)) {
                continue;
            }
            $layout = $bundle['layout'] ?? null;
            $languages = [];
            foreach ($files as $language => $hash) {
                if (is_string($hash) && self::isHash($hash)) {
                    $languages[(string) $language] = $hash;
                }
            }
            $bundles[(string) $id] = [
                'type' => $type,
                'name' => $bundleName,
                // A layout that is not a string is not flat either.
                'layout' => $layout === null || is_string($layout) ? $layout : '',
                'languages' => $languages,
            ];
        }

        return ['name' => $name, 'bundles' => $bundles];
    }

    public function readObject(string $hash): ?string
    {
        return self::isHash($hash) ? $this->disk->read($this->objectPath($hash)) : null;
    }

    public function hasObject(string $hash): bool
    {
        return self::isHash($hash) && $this->disk->mtime($this->objectPath($hash)) !== null;
    }

    public function putObject(string $hash, string $bytes): bool
    {
        return self::isHash($hash) && $this->disk->put($this->objectPath($hash), $bytes);
    }

    /** @param array<string, mixed> $index */
    public function putIndex(string $name, array $index): bool
    {
        return preg_match(self::INDEX_NAME, $name) === 1 && $this->putJson($this->dir . '/releases/' . $name, $index);
    }

    /** The last write of an install: until it lands, readers keep the old release. */
    public function putCurrent(HeldCopy $copy): bool
    {
        return $this->putJson($this->dir . '/current.json', [
            'release' => $copy->index,
            'releaseId' => $copy->releaseId,
            'checksum' => $copy->checksum,
            'distribution' => $copy->distribution,
            'stage' => $copy->stage,
            'installedAt' => $copy->installedAt,
            'previous' => $copy->previous,
        ]);
    }

    /**
     * Deletes what neither kept release names, a day after it stopped being named: a process that
     * started on the previous release may still be reading it. The age counts from the prune that
     * first saw the file unnamed (dropped.json), not from its mtime, which is when it was written.
     */
    public function prune(int $now, HeldCopy $held): void
    {
        $keepIndexes = [$held->index => true];
        if ($held->previous !== null) {
            $keepIndexes[$held->previous] = true;
        }
        $keepObjects = [];
        foreach (array_keys($keepIndexes) as $name) {
            foreach ($this->indexHashes((string) $name) ?? [] as $hash) {
                $keepObjects[$hash . '.json'] = true;
            }
        }

        $before = $this->readDropped();
        $dropped = [];
        $this->sweep($this->dir . '/objects', 'objects/', self::OBJECT_NAME, $keepObjects, $now, $before, $dropped);
        $this->sweep($this->dir . '/releases', 'releases/', self::INDEX_NAME, $keepIndexes, $now, $before, $dropped);
        $this->sweep($this->dir, '', null, [], $now, $before, $dropped);
        if ($dropped !== $before) {
            $this->putJson($this->dir . '/dropped.json', $dropped);
        }
    }

    /**
     * @param array<int|string, true> $keep
     * @param array<string, int> $before when each file was first seen unnamed
     * @param array<string, int> $dropped the same, for the files still there; filled here
     */
    private function sweep(string $dir, string $prefix, ?string $namePattern, array $keep, int $now, array $before, array &$dropped): void
    {
        foreach ($this->disk->files($dir) as $name) {
            if (preg_match(self::TEMP_NAME, $name) === 1) {
                $mtime = $this->disk->mtime($dir . '/' . $name);
                if ($mtime !== null && $now - $mtime > self::KEEP_SECONDS) {
                    $this->disk->delete($dir . '/' . $name);
                }

                continue;
            }
            if ($namePattern === null || preg_match($namePattern, $name) !== 1 || isset($keep[$name])) {
                continue;
            }
            // A recorded time ahead of now means the clock moved back: count from now instead.
            $since = isset($before[$prefix . $name]) ? min($before[$prefix . $name], $now) : $now;
            if ($now - $since > self::KEEP_SECONDS && $this->disk->delete($dir . '/' . $name)) {
                continue;
            }
            $dropped[$prefix . $name] = $since;
        }
    }

    /** @return array<string, int> */
    private function readDropped(): array
    {
        $dropped = [];
        foreach ($this->readJson($this->dir . '/dropped.json') ?? [] as $name => $at) {
            if (is_string($name) && is_int($at)) {
                $dropped[$name] = $at;
            }
        }

        return $dropped;
    }

    /** @return list<string>|null the hashes a release index names; null when it is unusable */
    private function indexHashes(string $name): ?array
    {
        $index = $this->readJson($this->dir . '/releases/' . $name);
        $bundles = $index['bundles'] ?? null;
        if (!is_array($bundles)) {
            return null;
        }
        $hashes = [];
        foreach ($bundles as $bundle) {
            $languages = is_array($bundle) ? ($bundle['languages'] ?? null) : null;
            if (!is_array($languages)) {
                return null;
            }
            foreach ($languages as $hash) {
                if (!is_string($hash) || !self::isHash($hash)) {
                    return null;
                }
                $hashes[$hash] = $hash;
            }
        }

        return $hashes === [] ? null : array_values($hashes);
    }

    private function objectPath(string $hash): string
    {
        return $this->dir . '/objects/' . $hash . '.json';
    }

    /** @return array<mixed>|null */
    private function readJson(string $path): ?array
    {
        $bytes = $this->disk->read($path);
        if ($bytes === null) {
            return null;
        }
        $decoded = json_decode($bytes, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private function putJson(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

        return is_string($json) && $this->disk->put($path, $json);
    }
}
