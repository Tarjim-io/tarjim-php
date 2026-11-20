<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Lets an in-process CLI run read a config holding test objects: its config file returns this. */
final class CliFixture
{
    /** @var array<string, mixed> */
    public static $config = [];

    public static function configFile(string $dir): string
    {
        $path = $dir . '/tarjim-config.php';
        file_put_contents($path, "<?php\nreturn \\Tarjim\\Tests\\Support\\CliFixture::\$config;\n");

        return $path;
    }
}
