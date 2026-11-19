<?php

declare(strict_types=1);

namespace Tarjim\Tests\Cli;

use Tarjim\Tests\Support\ZipBuilder;
use Tarjim\Wire\Response;

/** `export`: 0 written, 1 failed, 2 misconfigured. */
final class ExportCommandTest extends CliCase
{
    private const EXPORTS = 'https://api.example.test/projects/12/exports';

    /** @return array<string, mixed> */
    private function apiConfig(): array
    {
        return ['apiUrl' => 'https://api.example.test', 'apiKey' => 'tarjim-9999-0-1-not-a-real-key'];
    }

    private function serveExportZip(): void
    {
        $body = (string) json_encode(['id' => 501, 'status' => 'completed', 'fileName' => 'export.zip', 'fileType' => 'laravel-php', 'errorInfo' => null]);
        $this->fake->on(self::EXPORTS, new Response(201, [], $body));
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, [], (new ZipBuilder())->file('en/messages.php', 'EN')->bytes()));
    }

    public function testWritesTheFilesAndExitsZero(): void
    {
        $this->serveExportZip();
        $code = $this->cli(['export', '--config={config}', '--project=12', '--bundle=34', '--out=' . $this->root . '/lang'], $this->apiConfig());

        self::assertSame(0, $code, $this->err);
        self::assertFileExists($this->root . '/lang/en/messages.php');
        self::assertStringNotContainsString('tarjim-9999-0-1-not-a-real-key', $this->out . $this->err);
    }

    public function testAFailureIsOne(): void
    {
        $this->fake->on(self::EXPORTS, new Response(500));

        self::assertSame(1, $this->cli(['export', '--config={config}', '--project=12', '--bundle=34', '--out=' . $this->root . '/lang'], $this->apiConfig()));
    }

    public function testARefusedKeyIsTwo(): void
    {
        $this->fake->on(self::EXPORTS, new Response(401, [], '{"code":"x"}'));

        self::assertSame(2, $this->cli(['export', '--config={config}', '--project=12', '--bundle=34', '--out=' . $this->root . '/lang'], $this->apiConfig()));
        self::assertCount(1, $this->fake->requests);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public function badArguments(): iterable
    {
        yield 'no project' => [['export', '--config={config}', '--bundle=34', '--out=/tmp/x'], '--project'];
        yield 'no bundle' => [['export', '--config={config}', '--project=12', '--out=/tmp/x'], '--bundle'];
        yield 'no out' => [['export', '--config={config}', '--project=12', '--bundle=34'], '--out'];
        yield 'a project that is not a number' => [['export', '--config={config}', '--project=abc', '--bundle=34', '--out=/tmp/x'], '--project'];
        yield 'a relative out' => [['export', '--config={config}', '--project=12', '--bundle=34', '--out=lang'], '--out'];
    }

    /**
     * @dataProvider badArguments
     * @param list<string> $args
     */
    public function testBadArgumentsAreTwoAndCallNobody(array $args, string $named): void
    {
        self::assertSame(2, $this->cli($args, $this->apiConfig()));
        self::assertSame([], $this->fake->requests);
        self::assertStringContainsString($named, $this->err, 'the message names the bad option');
    }

    public function testExportNeedsNoDeliveryKey(): void
    {
        $this->serveExportZip();
        $code = $this->cli(['export', '--config={config}', '--project=12', '--bundle=34', '--out=' . $this->root . '/lang'], $this->apiConfig() + ['key' => null, 'deliveryUrl' => null, 'dir' => null]);

        self::assertSame(0, $code, $this->err);
    }
}
