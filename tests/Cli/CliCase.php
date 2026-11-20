<?php

declare(strict_types=1);

namespace Tarjim\Tests\Cli;

use Tarjim\Cli\Application;
use Tarjim\Tests\Support\CliFixture;
use Tarjim\Tests\Support\StoreCase;

abstract class CliCase extends StoreCase
{
    /** @var string */
    protected $out = '';

    /** @var string */
    protected $err = '';

    /** @param list<string> $args */
    protected function cli(array $args, array $overrides = []): int
    {
        CliFixture::$config = $this->config($overrides);
        $file = CliFixture::configFile($this->root);
        $args = array_map(static function (string $arg) use ($file): string {
            return str_replace('{config}', $file, $arg);
        }, $args);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application())->run($args, $out, $err);
        rewind($out);
        rewind($err);
        $this->out = (string) stream_get_contents($out);
        $this->err = (string) stream_get_contents($err);

        return $code;
    }
}
