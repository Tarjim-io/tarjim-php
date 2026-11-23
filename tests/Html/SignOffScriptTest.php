<?php

declare(strict_types=1);

namespace Tarjim\Tests\Html;

use Tarjim\Tests\Lookup\LookupCase;
use Tarjim\Tests\Support\ReleaseBuilder;

/** The script JP runs over released text to list what tHtml() would change. */
final class SignOffScriptTest extends LookupCase
{
    /** @return array{int, string, string} */
    private function runScript(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/thtml-diff.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testListsOnlyTheValuesThatWouldChange(): void
    {
        $this->installed((new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', [
                'safe' => '<b>Bold</b> and <a href="https://example.test">a link</a>',
                'unsafe' => 'Click <a href="javascript:alert(1)" onclick="x">here</a>',
                'styled' => '<p style="color:red">Red</p>',
                'legacy.link' => 'See <a href="%%link%%">this</a>',
                'named' => 'Hi {name}',
            ])
            ->flat('ns1', 'namespace', 'default', 'ar', ['unsafe' => '<script>x</script>مرحبا']));
        [$code, $out, $err] = $this->runScript(['--dir=' . $this->dir, '--legacy']);
        $lines = array_values(array_filter(explode("\n", $out)));
        $keys = array_map(static function (string $line): string {
            return explode("\t", $line)[1] . '/' . explode("\t", $line)[2];
        }, $lines);
        sort($keys);

        self::assertSame(1, $code, $err);
        self::assertSame(['ar/unsafe', 'en/styled', 'en/unsafe'], $keys);
        self::assertStringContainsString('6 values checked, 3 would change', $err);
    }

    public function testNothingToChangeIsZero(): void
    {
        $this->installed((new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', ['a' => 'plain', 'b' => '<b>ok</b>']));
        [$code, $out] = $this->runScript(['--dir=' . $this->dir]);

        self::assertSame(0, $code);
        self::assertSame('', $out);
    }

    public function testNoCopyIsTwo(): void
    {
        [$code] = $this->runScript(['--dir=' . $this->root . '/empty']);

        self::assertSame(2, $code);
        self::assertDirectoryDoesNotExist($this->root . '/empty');
    }
}
