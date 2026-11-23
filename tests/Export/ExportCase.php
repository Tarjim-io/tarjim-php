<?php

declare(strict_types=1);

namespace Tarjim\Tests\Export;

use Tarjim\Client;
use Tarjim\Export\ExportResult;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Tests\Support\ZipBuilder;
use Tarjim\Wire\Response;

abstract class ExportCase extends StoreCase
{
    protected const API = 'https://api.example.test';
    protected const EXPORTS = self::API . '/projects/12/exports';
    protected const API_KEY = 'tarjim-9999-0-1-not-a-real-key';

    /** @var string */
    protected $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = $this->root . '/lang';
    }

    protected function client(array $overrides = []): Client
    {
        return Client::create($this->config($overrides + ['apiUrl' => self::API, 'apiKey' => self::API_KEY]));
    }

    protected function pull(array $options = [], array $overrides = [], int $bundleId = 34): ExportResult
    {
        return $this->client($overrides)->exports()->pull(12, $bundleId, $this->out, $options);
    }

    /** @param array<string, mixed> $fields */
    protected static function export(int $id, string $status, array $fields = []): Response
    {
        return new Response(200, ['content-type' => 'application/json'], (string) json_encode($fields + [
            'id' => $id,
            'status' => $status,
            'fileName' => 'export.zip',
            'fileType' => 'laravel-php',
            'errorInfo' => null,
        ]));
    }

    /** POST answers 201 with a completed export; the download answers $bytes. */
    protected function serveExport(string $bytes, int $id = 501, array $fields = []): void
    {
        $created = self::export($id, 'completed', $fields);
        $this->fake->on(self::EXPORTS, new Response(201, $created->headers, $created->body));
        $this->fake->on(self::EXPORTS . '/' . $id, self::export($id, 'completed', $fields));
        $this->fake->on(self::EXPORTS . '/' . $id . '/download', new Response(200, ['content-type' => 'application/zip'], $bytes));
    }

    protected static function laravel(array $files): string
    {
        $zip = new ZipBuilder();
        foreach ($files as $name => $bytes) {
            $zip->file($name, $bytes);
        }

        return $zip->bytes();
    }

    /** @return array<string, string> every file under the target, relative path => bytes */
    protected function outFiles(): array
    {
        return $this->storeFiles($this->out);
    }
}
