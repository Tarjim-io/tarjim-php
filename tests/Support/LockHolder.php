<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** Another process holding the store's lock file. */
final class LockHolder
{
    /** @var resource */
    private $process;

    public function __construct(string $lockPath, float $seconds)
    {
        $process = proc_open(
            'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/hold-lock.php') . ' ' . escapeshellarg($lockPath) . ' ' . escapeshellarg((string) $seconds),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the lock holder');
        }
        $this->process = $process;
        if (trim((string) fgets($pipes[1])) !== 'locked') {
            throw new \RuntimeException('the lock holder did not take the lock');
        }
    }

    public function release(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
