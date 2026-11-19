<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

use Tarjim\Config;
use Tarjim\Image;
use Tarjim\Store\Disk;
use Tarjim\Store\Store;

/**
 * Reads the installed copy for one Client. Touches nothing: no write, no lock, no network, and
 * no folder created.
 */
final class Reader
{
    /** Seconds between two looks at current.json, so a busy process does not stat the disk per lookup. */
    private const RECHECK = 1;

    /** @var Store */
    private $store;

    /** @var Config */
    private $config;

    /** @var callable(string, string): void */
    private $log;

    /** @var bool */
    private $lower;

    /** @var bool */
    private $warned = false;

    /** @var string|null the release index file being served */
    private $loaded;

    /** @var array<string, array{type: string, name: string, languages: array<string, string>}> */
    private $bundles = [];

    /** @var int|null */
    private $checkedAt;

    /** @var array<string, Catalog|null> by object hash; null for a file that could not be read */
    private $catalogs = [];

    /** @param callable(string, string): void $log */
    private function __construct(Store $store, Config $config, callable $log)
    {
        $this->store = $store;
        $this->config = $config;
        $this->log = $log;
        $this->lower = $config->get('keyCase') === 'lower';
    }

    /** @param callable(string, string): void $log */
    public static function create(Config $config, callable $log): ?self
    {
        $dir = $config->get('dir');
        $disk = $config->get('disk');

        return is_string($dir) && $disk instanceof Disk ? new self(new Store($disk, $dir), $config, $log) : null;
    }

    /** @param array<string, mixed> $options */
    public function text(string $key, array $options): ?string
    {
        $catalog = $this->catalog($options);

        return $catalog === null ? null : $catalog->text($key);
    }

    /** @param array<string, mixed> $options */
    public function image(string $key, array $options): ?Image
    {
        $catalog = $this->catalog($options);

        return $catalog === null ? null : $catalog->image($key);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function locales(array $options): array
    {
        $this->reload();
        $id = BundleChoice::find($this->bundles, $this->choice($options, 'bundle', 'default'));

        return $id === null ? [] : array_map('strval', array_keys($this->bundles[$id]['languages']));
    }

    /** @param array<string, mixed> $options */
    private function catalog(array $options): ?Catalog
    {
        $this->reload();
        $id = BundleChoice::find($this->bundles, $this->choice($options, 'bundle', 'default'));
        if ($id === null) {
            return null;
        }
        $languages = $this->bundles[$id]['languages'];
        $tag = Languages::pick($languages, $this->optional($options, 'locale'), $this->configured('fallbackLocale'));
        if ($tag === null) {
            return null;
        }
        $hash = $languages[$tag];
        if (!array_key_exists($hash, $this->catalogs)) {
            $bytes = $this->store->readObject($hash);
            $this->catalogs[$hash] = $bytes === null ? null : Catalog::decode($bytes, $this->lower());
        }

        return $this->catalogs[$hash];
    }

    private function lower(): bool
    {
        if (!$this->lower || function_exists('mb_strtolower')) {
            return $this->lower;
        }
        if (!$this->warned) {
            $this->warned = true;
            ($this->log)('error', "keyCase 'lower' needs the mbstring extension; keys are matched exactly");
        }

        return false;
    }

    /**
     * Follows current.json at most once a second. A pointer that is missing or unreadable keeps
     * what is loaded; one that names another release switches to it whole.
     */
    private function reload(): void
    {
        $now = $this->now();
        if ($this->checkedAt !== null && $now - $this->checkedAt < self::RECHECK && $now >= $this->checkedAt) {
            return;
        }
        $this->checkedAt = $now;
        $name = $this->store->pointerName();
        if ($name === null || $name === $this->loaded) {
            return;
        }
        $index = $this->store->currentIndex();
        if ($index === null) {
            return;
        }
        $this->loaded = $index['name'];
        $this->bundles = $index['bundles'];
        $this->catalogs = [];
    }

    private function now(): int
    {
        $clock = $this->config->get('now');

        return is_callable($clock) ? (int) $clock() : time();
    }

    /** @param array<string, mixed> $options */
    private function optional(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->configured($name);
    }

    private function configured(string $name): ?string
    {
        $value = $this->config->get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $options */
    private function choice(array $options, string $name, string $default): string
    {
        return $this->optional($options, $name) ?? $default;
    }
}
