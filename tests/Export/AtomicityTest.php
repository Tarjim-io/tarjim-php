<?php

declare(strict_types=1);

namespace Tarjim\Tests\Export;

use Tarjim\Export\ExportResult;
use Tarjim\Tests\Support\FakeTransport;
use Tarjim\Tests\Support\ZipBuilder;
use Tarjim\Wire\Response;

/** A pull that fails changes nothing; one that succeeds never takes over a file it did not write. */
final class AtomicityTest extends ExportCase
{
    private function pullZip(ZipBuilder $zip, int $id = 501, array $options = []): ExportResult
    {
        $this->fake = new FakeTransport();
        $this->serveExport($zip->bytes(), $id);

        return $this->pull($options);
    }

    private function first(): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'OLD-EN')->file('fr/messages.php', 'OLD-FR')->file('de/messages.php', 'OLD-DE'));
        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
    }

    /** @return list<string> */
    private function listed(): array
    {
        $data = json_decode((string) @file_get_contents($this->out . '/.tarjim-export.json'), true);
        $files = is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [];
        sort($files);

        return $files;
    }

    public function testAFileTheAppOwnsIsNeverOverwritten(): void
    {
        mkdir($this->out . '/en', 0755, true);
        file_put_contents($this->out . '/en/validation.php', 'LARAVEL-OWN');
        $result = $this->pullZip((new ZipBuilder())->file('en/validation.php', 'FROM-EXPORT')->file('en/messages.php', 'EN'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertStringContainsString('en/validation.php', $result->message);
        self::assertSame('LARAVEL-OWN', file_get_contents($this->out . '/en/validation.php'));
        self::assertFileDoesNotExist($this->out . '/en/messages.php');
    }

    public function testTakingOverExistingFilesIsAnExplicitChoice(): void
    {
        mkdir($this->out . '/en', 0755, true);
        file_put_contents($this->out . '/en/messages.php', 'WRITTEN-BY-THE-OLD-CLIENT');
        $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'EN'), 501, ['adoptExisting' => true]);

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame('EN', file_get_contents($this->out . '/en/messages.php'));
        self::assertSame(['en/messages.php'], $this->listed());
    }

    public function testAFileTheAppOwnsUnderAnotherCaseIsNeverOverwritten(): void
    {
        mkdir($this->out . '/en', 0755, true);
        file_put_contents($this->out . '/en/validation.php', 'LARAVEL-OWN');
        $result = $this->pullZip((new ZipBuilder())->file('en/Validation.php', 'FROM-EXPORT'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('LARAVEL-OWN', file_get_contents($this->out . '/en/validation.php'));
    }

    public function testTwoEntriesThatDifferOnlyInCaseAreRefused(): void
    {
        $result = $this->pullZip((new ZipBuilder())->file('en/a.php', 'a')->file('en/A.php', 'A'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
    }

    public function testTwoEntriesThatDifferOnlyInUnicodeFormAreRefused(): void
    {
        $nfc = 'fr/caf' . "\u{00E9}" . '.php';
        $nfd = 'fr/cafe' . "\u{0301}" . '.php';
        $result = $this->pullZip((new ZipBuilder())->file($nfc, '1')->file($nfd, '2'));

        self::assertSame(ExportResult::FAILED, $result->status);
    }

    public function testAFolderRenamedOnlyInCaseKeepsItsFiles(): void
    {
        $this->pullZip((new ZipBuilder())->file('fr/messages.php', 'FR'));
        $result = $this->pullZip((new ZipBuilder())->file('FR/messages.php', 'FR2'), 502);
        $files = array_change_key_case($this->outFiles(), CASE_LOWER);

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame('FR2', $files['fr/messages.php'] ?? null);
    }

    public function testAZipWhoseEntriesClashIsRefusedBeforeAnythingMoves(): void
    {
        $this->first();
        $before = $this->outFiles();
        $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'NEW')->file('zz', 'file')->file('zz/x.php', 'x'), 502);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($before, $this->outFiles());
    }

    public function testANameTooLongForTheDiskIsRefusedBeforeAnythingMoves(): void
    {
        $this->first();
        $before = $this->outFiles();
        $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'NEW')->file('en/' . str_repeat('n', 300) . '.php', 'x'), 502);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($before, $this->outFiles());
    }

    public function testAFailureWhilePlacingPutsEverythingBack(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::fail('run this suite as a user other than root');
        }
        $this->first();
        $before = $this->outFiles();
        chmod($this->out . '/fr', 0555);
        try {
            $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'NEW-EN')->file('fr/messages.php', 'NEW-FR')->file('de/messages.php', 'OLD-DE'), 502);
        } finally {
            chmod($this->out . '/fr', 0755);
        }

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($before, $this->outFiles());
    }

    public function testAFileThatCouldNotBeRemovedIsTriedAgainNextTime(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::fail('run this suite as a user other than root');
        }
        $this->first();
        chmod($this->out . '/de', 0555);
        try {
            $result = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'EN')->file('fr/messages.php', 'FR'), 502);
        } finally {
            chmod($this->out . '/de', 0755);
        }

        self::assertContains('de/messages.php', $this->listed(), 'still owned, so a later pull removes it');
        self::assertStringContainsString('de/messages.php', $result->message);
        $again = $this->pullZip((new ZipBuilder())->file('en/messages.php', 'EN')->file('fr/messages.php', 'FR'), 503);
        self::assertSame(['de/messages.php'], $again->removed);
        self::assertFileDoesNotExist($this->out . '/de/messages.php');
    }

    public function testFilesPlacedBeforeTheListCouldBeWrittenAreStillOwned(): void
    {
        $this->first();
        $disk = new \Tarjim\Tests\Support\FaultyDisk();
        $this->fake = new FakeTransport();
        $this->serveExport((new ZipBuilder())->file('en/messages.php', 'EN')->file('es/messages.php', 'ES')->bytes(), 502);
        $disk->failPutsMatching = '#/\.tarjim-export\.json$#';
        $result = $this->pull([], ['disk' => $disk]);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertFileDoesNotExist($this->out . '/es/messages.php', 'nothing is placed when the list cannot be written first');
        self::assertSame('OLD-EN', $this->outFiles()['en/messages.php'] ?? null);
    }

    public function testAPollAnsweringAnotherExportIsNotBelieved(): void
    {
        $this->fake->on(self::EXPORTS, new Response(202, [], (string) self::export(501, 'pending')->body));
        $this->fake->on(self::EXPORTS . '/501', self::export(777, 'completed'));
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, [], self::laravel(['en/a.php' => 'x'])));
        $result = $this->pull(['timeout' => 20]);

        self::assertNotSame(777, $result->exportId);
        $list = json_decode((string) @file_get_contents($this->out . '/.tarjim-export.json'), true);
        self::assertNotSame(777, is_array($list) ? ($list['exportId'] ?? null) : null, 'the list records the export it downloaded');
    }

    public function testTheServersErrorTextNeverCarriesTheKey(): void
    {
        $this->fake->on(self::EXPORTS, new Response(201, [], (string) self::export(501, 'failed', ['errorInfo' => 'bad key ' . self::API_KEY])->body));
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertStringNotContainsString(self::API_KEY, $result->message);
        self::assertStringNotContainsString(self::API_KEY, serialize($this->logged));
    }

    public function testAFailedFirstPullLeavesNoTargetFolder(): void
    {
        $this->serveExport('not a zip');
        $this->pull();

        self::assertDirectoryDoesNotExist($this->out);
    }

    /** @return iterable<string, array{string}> */
    public function hiddenNames(): iterable
    {
        yield 'htaccess' => ['.htaccess'];
        yield 'env' => ['.env'];
    }

    /** @dataProvider hiddenNames */
    public function testASingleFileWithAHiddenNameIsRefused(string $name): void
    {
        $this->fake->on(self::EXPORTS, new Response(201, [], (string) self::export(501, 'completed', ['fileName' => $name, 'fileType' => 'csv'])->body));
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, [], "a,b\n"));

        self::assertSame(ExportResult::FAILED, $this->pull()->status);
        self::assertFileDoesNotExist($this->out . '/' . $name);
    }
}
