<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class Manifest
{
    /** @var int */
    public $schemaVersion;

    /** @var array<string, array{type: string, name: string, layout: string|null}> by bundle id */
    public $bundles;

    /** @var array<string, array<string, FileRef>> bundle id => language => the json file */
    public $files;

    /**
     * @param array<string, array{type: string, name: string, layout: string|null}> $bundles
     * @param array<string, array<string, FileRef>> $files
     */
    public function __construct(int $schemaVersion, array $bundles, array $files)
    {
        $this->schemaVersion = $schemaVersion;
        $this->bundles = $bundles;
        $this->files = $files;
    }

    /**
     * Lenient: an entry that is not understood is dropped; null only when the whole is unusable.
     * Only the json file of a slice is read, so a bundle that lists none (a native one) is dropped
     * whole rather than left to shadow a json bundle of the same type and name.
     */
    public static function decode(string $bytes): ?self
    {
        // Objects, not arrays: only that tells a JSON `{}` from a `[]`.
        $root = json_decode($bytes, false);
        if (!$root instanceof \stdClass) {
            return null;
        }
        $schemaVersion = $root->schemaVersion ?? null;
        $bundles = $root->bundles ?? null;
        $slices = $root->slices ?? null;
        if (!is_int($schemaVersion) || !$bundles instanceof \stdClass || !$slices instanceof \stdClass) {
            return null;
        }

        $files = [];
        foreach (get_object_vars($slices) as $id => $locales) {
            if (!$locales instanceof \stdClass) {
                continue;
            }
            foreach (get_object_vars($locales) as $locale => $kinds) {
                $ref = $kinds instanceof \stdClass ? self::jsonFile($kinds->json ?? null) : null;
                if ($ref !== null) {
                    $files[$id][$locale] = $ref;
                }
            }
        }

        $kept = [];
        foreach (get_object_vars($bundles) as $id => $bundle) {
            if (!$bundle instanceof \stdClass || !isset($files[$id])) {
                continue;
            }
            $type = $bundle->type ?? null;
            $name = $bundle->name ?? null;
            $layout = $bundle->layout ?? null;
            if (!is_string($type) || !is_string($name)) {
                continue;
            }
            // A layout that is not a string is not flat either.
            $kept[$id] = ['type' => $type, 'name' => $name, 'layout' => $layout === null || is_string($layout) ? $layout : ''];
        }

        // A file list for a bundle that was dropped (or never declared) is of no use to anyone.
        return new self($schemaVersion, $kept, array_intersect_key($files, $kept));
    }

    /** @param mixed $json */
    private static function jsonFile($json): ?FileRef
    {
        if (!$json instanceof \stdClass) {
            return null;
        }
        $hash = $json->hash ?? null;
        $size = $json->size ?? null;
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}\z/', $hash) !== 1 || !is_int($size) || $size < 0) {
            return null;
        }

        return new FileRef($hash, $size);
    }
}
