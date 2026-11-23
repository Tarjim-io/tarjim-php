<?php

declare(strict_types=1);

namespace Tarjim\Tests\Curl;

use Tarjim\Client;
use Tarjim\Export\ExportResult;
use Tarjim\Tests\Support\Route;
use Tarjim\Tests\Support\StoreCase;
use Tarjim\Tests\Support\TestServer;
use Tarjim\Tests\Support\ZipBuilder;

/** The export pull over real curl, against a local server playing the API. */
final class ExportOverCurlTest extends StoreCase
{
    public function testPostsTheBundleAndInstallsTheZip(): void
    {
        $server = new TestServer();
        $server->route('/projects/12/exports', Route::json(201, ['id' => 501, 'status' => 'completed', 'fileName' => 'export.zip', 'fileType' => 'laravel-php', 'errorInfo' => null]));
        $server->route('/projects/12/exports/501/download', Route::answer(200, (new ZipBuilder())->file('en/messages.php', 'EN')->bytes(), ['Content-Type' => 'application/zip'])->gzip());
        $client = Client::create(['apiUrl' => $server->origin(), 'apiKey' => 'tarjim-9999-0-1-not-a-real-key']);
        $result = $client->exports()->pull(12, 34, $this->root . '/lang');
        $requests = $server->requests();
        $server->stop();

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame('EN', file_get_contents($this->root . '/lang/en/messages.php'));
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('tarjim-9999-0-1-not-a-real-key', TestServer::headerOf($requests[0], 'x-tarjim-apikey'));
    }
}
