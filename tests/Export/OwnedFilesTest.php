<?php

declare(strict_types=1);

namespace Tarjim\Tests\Export;

use Tarjim\Export\ExportResult;
use Tarjim\Wire\Response;

/** The pull owns the files it wrote, never the folder: everything else in it is left alone. */
final class OwnedFilesTest extends ExportCase
{
    private function pullFiles(array $files, int $id = 501): ExportResult
    {
        $this->fake = new \Tarjim\Tests\Support\FakeTransport();
        $this->serveExport(self::laravel($files), $id);
        $result = $this->pull();
        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);

        return $result;
    }

    public function testWritesTheExportedFilesAndAListOfThem(): void
    {
        $result = $this->pullFiles(['en/messages.php' => 'EN', 'fr/messages.php' => 'FR']);
        $files = $this->outFiles();

        self::assertSame('EN', $files['en/messages.php'] ?? null);
        self::assertSame('FR', $files['fr/messages.php'] ?? null);
        $written = $result->written;
        sort($written);
        self::assertSame(['en/messages.php', 'fr/messages.php'], $written);
        self::assertCount(3, $files, 'the two files and the list of written paths');
    }

    public function testTheNextPullRemovesOnlyWhatItWroteAndNoLongerHas(): void
    {
        $this->pullFiles(['en/messages.php' => 'EN', 'en/old.php' => 'OLD']);
        $result = $this->pullFiles(['en/messages.php' => 'EN2'], 502);
        $files = $this->outFiles();

        self::assertSame('EN2', $files['en/messages.php'] ?? null);
        self::assertArrayNotHasKey('en/old.php', $files);
        self::assertSame(['en/old.php'], $result->removed);
    }

    public function testFilesItNeverWroteAreUntouched(): void
    {
        mkdir($this->out . '/en', 0755, true);
        file_put_contents($this->out . '/en/validation.php', 'LARAVEL');
        file_put_contents($this->out . '/README', 'mine');
        $this->pullFiles(['en/messages.php' => 'EN']);
        $this->pullFiles(['fr/messages.php' => 'FR'], 502);
        $files = $this->outFiles();

        self::assertSame('LARAVEL', $files['en/validation.php'] ?? null);
        self::assertSame('mine', $files['README'] ?? null);
    }

    public function testAFailedPullLeavesThePreviousFilesAndList(): void
    {
        $this->pullFiles(['en/messages.php' => 'EN', 'en/old.php' => 'OLD']);
        $before = $this->outFiles();
        $this->fake = new \Tarjim\Tests\Support\FakeTransport();
        $this->serveExport(self::laravel(['en/messages.php' => 'NEW']), 502);
        $this->fake->on(self::EXPORTS . '/502/download', new Response(200, [], 'not a zip at all'));
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($before, $this->outFiles());
    }

    public function testNoTemporaryFolderIsLeftBehind(): void
    {
        $this->pullFiles(['en/messages.php' => 'EN']);
        $this->fake = new \Tarjim\Tests\Support\FakeTransport();
        $this->serveExport('garbage', 502);
        $this->pull();
        $this->pullFiles(['en/messages.php' => 'EN3'], 503);

        $entries = array_values(array_diff(scandir($this->out) ?: [], ['.', '..']));
        sort($entries);
        self::assertSame('en', $entries[count($entries) - 1]);
        foreach ($entries as $entry) {
            self::assertTrue($entry === 'en' || strpos($entry, '.tarjim') === 0, $entry);
            self::assertDoesNotMatchRegularExpression('/tmp|stag/i', $entry);
        }
    }

    public function testAHandEditedListCannotDeleteOutsideTheTarget(): void
    {
        $this->pullFiles(['en/messages.php' => 'EN']);
        file_put_contents($this->root . '/precious.txt', 'keep');
        foreach (glob($this->out . '/.tarjim*') ?: [] as $list) {
            $data = json_decode((string) file_get_contents($list), true);
            if (is_array($data)) {
                $data['files'] = ['../precious.txt', '/etc/hosts', 'en/messages.php'];
                file_put_contents($list, json_encode($data));
            }
        }
        $this->pullFiles(['fr/messages.php' => 'FR'], 502);

        self::assertFileExists($this->root . '/precious.txt');
    }

    public function testASingleFileExportIsWrittenUnderItsName(): void
    {
        $this->fake->on(self::EXPORTS, new Response(201, [], (string) self::export(501, 'completed', ['fileName' => 'translations.csv', 'fileType' => 'csv'])->body));
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, ['content-type' => 'text/csv', 'content-disposition' => 'attachment; filename="translations.csv"'], "key,en\nhi,Hi\n"));
        $result = $this->pull();

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame("key,en\nhi,Hi\n", $this->outFiles()['translations.csv'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public function unsafeSingleNames(): iterable
    {
        yield 'a path' => ['../escape.csv'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'hidden list name' => ['.tarjim-export.json'];
    }

    /** @dataProvider unsafeSingleNames */
    public function testASingleFileWithAnUnsafeNameIsRefused(string $name): void
    {
        $this->fake->on(self::EXPORTS, new Response(201, [], (string) self::export(501, 'completed', ['fileName' => $name, 'fileType' => 'csv'])->body));
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, ['content-type' => 'text/csv'], "a,b\n"));
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertFileDoesNotExist($this->root . '/escape.csv');
    }

    public function testUtf8FileNamesSurvive(): void
    {
        $this->pullFiles(['ar/رسائل.php' => 'AR']);

        self::assertSame('AR', $this->outFiles()['ar/رسائل.php'] ?? null);
    }

    public function testFilesGetPlainModes(): void
    {
        $previous = umask(0077);
        try {
            $this->pullFiles(['en/messages.php' => 'EN']);
        } finally {
            umask($previous);
        }
        clearstatcache();

        self::assertSame('0644', substr(sprintf('%o', fileperms($this->out . '/en/messages.php')), -4));
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->out . '/en')), -4));
    }
}
