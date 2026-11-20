<?php

declare(strict_types=1);

namespace Tarjim\Tests\Cli;

use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Tests\Support\TestServer;

/** The installed command, run as a process against a local server. */
final class BinaryTest extends StoreCase
{
    /** @return array{int, string} */
    private static function runBinary(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/tarjim-php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function testRefreshesThenReportsStatus(): void
    {
        $server = new TestServer();
        $release = (new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello']);
        $url = $release->serveOrigin($server);
        $config = $this->root . '/config.php';
        file_put_contents($config, '<?php return ' . var_export(['dir' => $this->dir, 'deliveryUrl' => $url, 'key' => ReleaseBuilder::KEY], true) . ';');
        [$refreshCode, $refreshOut] = self::runBinary(['refresh', '--config=' . $config, '--require-copy']);
        [$statusCode, $statusOut] = self::runBinary(['status', '--config=' . $config]);
        $server->stop();

        self::assertSame(0, $refreshCode, $refreshOut);
        self::assertSame(0, $statusCode, $statusOut);
        self::assertStringNotContainsString(ReleaseBuilder::KEY, $refreshOut . $statusOut);
    }

    public function testNoArgumentsIsTwo(): void
    {
        [$code] = self::runBinary([]);

        self::assertSame(2, $code);
    }
}
