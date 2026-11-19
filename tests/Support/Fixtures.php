<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Recorded or hand-written answers under tests/fixtures; never a real key or customer text. */
final class Fixtures
{
    public static function path(string $name): string
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException('no fixture ' . $name);
        }

        return $path;
    }

    public static function bytes(string $name): string
    {
        return (string) file_get_contents(self::path($name));
    }

    /** @return mixed */
    public static function json(string $name)
    {
        return json_decode(self::bytes($name), true, 512, JSON_THROW_ON_ERROR);
    }
}
