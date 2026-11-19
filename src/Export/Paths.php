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

    /** A plain file name: a safe path of one segment. */
    public static function isBasename(string $name): bool
    {
        return strpos($name, '/') === false && self::isSafe($name);
    }
}
