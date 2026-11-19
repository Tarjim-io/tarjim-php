<?php

declare(strict_types=1);

namespace Tarjim\Export;

final class Paths
{
    public const LIST_NAME = '.tarjim-export.json';

    /** Anything starting with this in the target's top folder is ours: the list and the staging folders. */
    public const OWN_PREFIX = '.tarjim-export';

    /**
     * Whether a relative path, from a zip entry or from a recorded list, can only name something
     * inside the target. Backslashes and drive letters are refused outright: a name that is harmless
     * here would be a traversal on another platform, and the same archive may be unpacked on both.
     */
    public static function isSafe(string $path): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1 || preg_match('//u', $path) !== 1) {
            return false;
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return stripos($segments[0], self::OWN_PREFIX) !== 0;
    }

    /** Longest name and path the common file systems accept, in bytes. */
    private const MAX_NAME = 255;
    private const MAX_PATH = 1024;

    /** Whether no segment and not the whole path is longer than a disk will take. */
    public static function fitsDisk(string $path): bool
    {
        if (strlen($path) > self::MAX_PATH) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (strlen($segment) > self::MAX_NAME) {
                return false;
            }
        }

        return true;
    }

    /**
     * What two paths are compared by: names that differ only in case, or in Unicode form, are one
     * file on some disks, so they must be one here too.
     */
    public static function key(string $path): string
    {
        if (class_exists('Normalizer')) {
            $normal = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $path = $normal;
            }
        }

        return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
    }

    /** A plain file name: a safe path of one segment. */
    public static function isBasename(string $name): bool
    {
        return strpos($name, '/') === false && self::isSafe($name);
    }
}
