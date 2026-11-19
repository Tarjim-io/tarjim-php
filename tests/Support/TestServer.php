<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** A `php -S` server on a free loopback port, answering from Routes and logging every request. One request at a time. */
final class TestServer
{
    /** @var resource */
    private $process;

    /** @var string */
    private $dir;

    /** @var int */
    public $port;

    /** @var string */
    public $host;

    public function __construct(string $host = '127.0.0.1')
    {
        $this->host = $host;
        $this->dir = sys_get_temp_dir() . '/tarjim-test-server-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/routes', 0755, true);
        touch($this->dir . '/requests.log');
        $this->port = self::freePort();

        $env = (array) getenv() + [];
        $env['TARJIM_TEST_SERVER_DIR'] = $this->dir;
        // One process: with workers, terminating the master leaves them running. A stalled answer
        // therefore holds up the next request to the same server; give stall tests their own.
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/server/router.php'];
        $process = proc_open(
            self::commandLine($command),
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.out', 'a'], 2 => ['file', $this->dir . '/server.out', 'a']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the test server');
        }
        $this->process = $process;
        $this->waitUntilListening();
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/routes/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->dir . '/routes');
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    /** `http://<host>:<port>` — the host as given, so 127.0.0.1 and localhost are two origins. */
    public function origin(?string $host = null): string
    {
        return 'http://' . ($host ?? $this->host) . ':' . $this->port;
    }

    public function route(string $path, Route $route): self
    {
        file_put_contents($this->dir . '/routes/' . sha1($path) . '.json', json_encode($route->spec));

        return $this;
    }

    /** @return list<array{method: string, path: string, query: string, headers: array<string, string>}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->dir . '/requests.log')));

        return array_values(array_map(static function (string $line): array {
            return json_decode($line, true);
        }, $lines));
    }

    /** Header value as the server received it, matching the name in any case. */
    public static function headerOf(array $request, string $name): ?string
    {
        foreach ($request['headers'] as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('no free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the test server did not start: ' . (string) @file_get_contents($this->dir . '/server.out'));
    }

    /** @param list<string> $parts */
    private static function commandLine(array $parts): string
    {
        // proc_open takes an array only from PHP 7.4 on Unix, and `exec` keeps the server as the
        // process proc_terminate signals.
        return 'exec ' . implode(' ', array_map('escapeshellarg', $parts));
    }
}
