<?php

declare(strict_types=1);

namespace Tarjim\Tests\Docs;

use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Tests\Support\TestServer;

/**
 * The staging check JP runs against a real stage before a release, run here against a local
 * server playing it. The key comes from the environment only.
 */
final class StagingCheckTest extends StoreCase
{
    /** @return array{int, string, string} */
    private static function runCheck(array $args, array $env): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/staging-check.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function serveStage(TestServer $server): string
    {
        $release = (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello', 'html' => '<b>bold</b>'])
            ->flat('ns1', 'namespace', 'default', 'fr', ['hello' => 'Bonjour']);

        return $release->serveOrigin($server);
    }

    public function testAPassingStageExitsZeroAndSaysWhatItSaw(): void
    {
        $server = new TestServer();
        $url = $this->serveStage($server);
        [$code, $out, $err] = self::runCheck(['--delivery-url=' . $url, '--bundle=default'], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('origin', $out);
        self::assertStringContainsString('release 7', $out);
        self::assertStringContainsString('en', $out);
        self::assertStringNotContainsString(ReleaseBuilder::KEY, $out . $err);
    }

    public function testTheKeyIsNeverTakenFromTheCommandLine(): void
    {
        $server = new TestServer();
        $url = $this->serveStage($server);
        [$code, $out, $err] = self::runCheck(['--delivery-url=' . $url, '--key=' . ReleaseBuilder::KEY], []);
        $server->stop();

        self::assertSame(2, $code);
        self::assertStringContainsString('TARJIM_KEY', $err);
    }

    public function testAFailingStageExitsOne(): void
    {
        $server = new TestServer();
        [$code] = self::runCheck(['--delivery-url=' . $server->origin() . '/delivery/missing/meta'], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertSame(1, $code);
    }

    public function testItLeavesNoCopyBehind(): void
    {
        $server = new TestServer();
        $url = $this->serveStage($server);
        $before = glob(sys_get_temp_dir() . '/tarjim-staging-check-*') ?: [];
        self::runCheck(['--delivery-url=' . $url], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertSame($before, glob(sys_get_temp_dir() . '/tarjim-staging-check-*') ?: []);
    }

    public function testRecordedFixturesCarryNoKeyNoSignatureAndNoText(): void
    {
        $server = new TestServer();
        $url = $this->serveStage($server);
        $out = $this->root . '/fixtures';
        [$code, $stdout, $err] = self::runCheck(['--delivery-url=' . $url, '--record=' . $out], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertSame(0, $code, $stdout . $err);
        $files = glob($out . '/*') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file);
            self::assertStringNotContainsString(ReleaseBuilder::KEY, $bytes, basename($file));
            self::assertStringNotContainsString('Bonjour', $bytes, basename($file));
            self::assertStringNotContainsString('Hello', $bytes, basename($file));
            self::assertDoesNotMatchRegularExpression('/Signature=(?!REDACTED)/', $bytes, basename($file));
        }
    }

    public function testRecordedFixturesKeepNoLabelsOrExtraFields(): void
    {
        $server = new TestServer();
        $release = (new ReleaseBuilder('7'))
            ->slice('ns1', ['type' => 'namespace', 'name' => 'AcmeBundle', 'layout' => 'flat', 'namespaces' => ['AcmeNamespace']], 'en', [
                'schemaVersion' => 1, 'bundle' => 'ns1', 'locale' => 'en', 'projectName' => 'AcmeProject',
                'keys' => ['hello' => ['id' => '1', 'value' => 'Hello', 'type' => 'AcmeType', 'attributes' => ['data-acme' => 'x', 'alt' => 'AcmeAlt'], 'note' => 'AcmeNote']],
            ]);
        $url = $release->serveOrigin($server);
        $server->route('/delivery/dst_test/meta', \Tarjim\Tests\Support\Route::json(200, $release->meta([
            'distribution' => 'AcmeDistribution',
            'manifestUrl' => 'released/7/manifest',
        ])));
        $out = $this->root . '/fixtures';
        self::runCheck(['--delivery-url=' . $url, '--bundle=AcmeBundle', '--record=' . $out], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        $all = '';
        foreach (glob($out . '/*') ?: [] as $file) {
            $all .= (string) file_get_contents($file);
        }
        self::assertNotSame('', $all);
        foreach (['Acme', 'Hello', 'data-acme'] as $label) {
            self::assertStringNotContainsString($label, $all, $label . ' survived recording');
        }
    }

    public function testRecordedUrlsKeepNoQuery(): void
    {
        $server = new TestServer();
        $release = (new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello']);
        $url = $release->serveOrigin($server);
        $server->route('/delivery/dst_test/meta', \Tarjim\Tests\Support\Route::json(200, $release->meta([
            'manifestUrl' => 'released/7/manifest?Signature=SIGSECRET&Expires=1',
        ])));
        $server->route('/delivery/dst_test/released/7/manifest', \Tarjim\Tests\Support\Route::answer(200, $release->manifestBytes()));
        $out = $this->root . '/fixtures';
        self::runCheck(['--delivery-url=' . $url, '--record=' . $out], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertStringNotContainsString('SIGSECRET', (string) @file_get_contents($out . '/meta.json'));
    }

    public function testServerTextIsPrintedWithoutControlCharacters(): void
    {
        $server = new TestServer();
        $release = (new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello']);
        $url = $release->serveOrigin($server);
        $server->route('/delivery/dst_test/meta', \Tarjim\Tests\Support\Route::json(200, $release->meta(['distribution' => "dst\x1b[2Jcleared"])));
        [, $out] = self::runCheck(['--delivery-url=' . $url], ['TARJIM_KEY' => ReleaseBuilder::KEY]);
        $server->stop();

        self::assertStringNotContainsString("\x1b", $out);
    }
}
