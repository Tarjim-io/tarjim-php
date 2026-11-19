<?php

declare(strict_types=1);

namespace Tarjim\Cli;

final class Application
{
    public const OK = 0;
    public const FAILED = 1;
    public const MISCONFIGURED = 2;
    public const WAIT_RAN_OUT = 3;
    public const NO_COPY = 4;

    /**
     * @param list<string> $argv without the program name
     * @param resource $out
     * @param resource $err
     */
    public function run(array $argv, $out, $err): int
    {
        throw new \LogicException('not implemented');
    }
}
