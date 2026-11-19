<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

use Tarjim\Image;

/** One bundle in one language, decoded once. */
final class Catalog
{
    private const IMAGE_ATTRIBUTES = ['alt', 'title', 'width', 'height', 'loading'];

    /** @var array<string, \stdClass> leaf by key name, folded when the client folds */
    private $leaves;

    /** @var bool */
    private $lower;

    /** @param array<string, \stdClass> $leaves */
    private function __construct(array $leaves, bool $lower)
    {
        $this->leaves = $leaves;
        $this->lower = $lower;
    }

    /** Lower-casing with both Greek sigmas folded to one, so "ΟΔΟΣ" and "ΟΔΟΣ.one" share a prefix as before. */
    public static function fold(string $key): string
    {
        return str_replace('ς', 'σ', mb_strtolower($key, 'UTF-8'));
    }

    /** Null for anything but a readable version-1 file with an object of keys. */
    public static function decode(string $bytes, bool $lower): ?self
    {
        // Objects, not arrays: only that tells `{}` from `[]`, and a name like "404" stays a string.
        $root = json_decode($bytes, false);
        if (!$root instanceof \stdClass || ($root->schemaVersion ?? null) !== 1 || !($root->keys ?? null) instanceof \stdClass) {
            return null;
        }
        $leaves = [];
        foreach (get_object_vars($root->keys) as $name => $leaf) {
            if (!$leaf instanceof \stdClass) {
                continue;
            }
            $name = (string) $name;
            $name = $lower ? self::fold($name) : $name;
            // A clash resolves the same way every time: the first in the file wins.
            if (!isset($leaves[$name])) {
                $leaves[$name] = $leaf;
            }
        }

        return new self($leaves, $lower);
    }

    public function text(string $key): ?string
    {
        $leaf = $this->leaf($key);
        $value = $leaf->value ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function image(string $key): ?Image
    {
        $leaf = $this->leaf($key);
        if ($leaf === null || ($leaf->type ?? null) !== 'image') {
            return null;
        }
        $url = $leaf->value ?? null;
        if (!is_string($url) || !self::isHttpsUrl($url)) {
            return null;
        }
        $attributes = [];
        $raw = $leaf->attributes ?? null;
        if ($raw instanceof \stdClass) {
            foreach (get_object_vars($raw) as $name => $value) {
                if (is_string($value) && in_array((string) $name, self::IMAGE_ATTRIBUTES, true)) {
                    $attributes[(string) $name] = $value;
                }
            }
        }

        return new Image($url, $attributes);
    }

    private function leaf(string $key): ?\stdClass
    {
        return $this->leaves[$this->lower ? self::fold($key) : $key] ?? null;
    }

    private static function isHttpsUrl(string $url): bool
    {
        if (preg_match('/^[\x00-\x20]|[\x00-\x20]$/', $url) === 1 || preg_match('#^https://#i', $url) !== 1) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '';
    }
}
