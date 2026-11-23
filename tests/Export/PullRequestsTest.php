<?php

declare(strict_types=1);

namespace Tarjim\Tests\Export;

use Tarjim\Export\ExportResult;
use Tarjim\Wire\Response;

final class PullRequestsTest extends ExportCase
{
    public function testCreatesTheExportFromTheBundle(): void
    {
        $this->serveExport(self::laravel(['en/messages.php' => "<?php return ['hi' => 'Hi'];"]));
        $result = $this->pull();

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertSame(501, $result->exportId);
        $post = $this->fake->requests[0];
        self::assertSame('POST', $post->method);
        self::assertSame(self::EXPORTS, $post->url);
        self::assertSame(['customBundleId' => 34], json_decode((string) $post->body, true));
        $headers = array_change_key_case($post->headers, CASE_LOWER);
        self::assertSame(self::API_KEY, $headers['x-tarjim-apikey'] ?? null);
        self::assertSame('2026-07-29', $headers['x-tarjim-api-version'] ?? null);
        self::assertStringContainsString('application/json', $headers['content-type'] ?? '');
    }

    public function testTheCreateRequestWaitsUpToAMinute(): void
    {
        $this->serveExport(self::laravel(['en/a.php' => 'x']));
        $this->pull();

        self::assertSame(60, $this->fake->requests[0]->stallTimeout);
    }

    public function testTheKeyIsNeverInAUrl(): void
    {
        $this->serveExport(self::laravel(['en/a.php' => 'x']));
        $this->pull();

        foreach ($this->fake->requests as $request) {
            self::assertStringNotContainsString(self::API_KEY, $request->url);
        }
    }

    public function testPollsAQueuedExportUntilItCompletes(): void
    {
        $this->fake->on(self::EXPORTS, new Response(202, [], (string) self::export(501, 'pending')->body));
        $this->fake->on(self::EXPORTS . '/501', [self::export(501, 'pending'), self::export(501, 'processing'), self::export(501, 'completed')]);
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, [], self::laravel(['en/a.php' => 'x'])));
        $result = $this->pull();

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertCount(1, array_filter($this->fake->requests, static function ($r): bool {
            return $r->method === 'POST';
        }));
        self::assertNotEmpty($this->clock->slept);
    }

    public function testAnExportThatFailsOnTheServerChangesNothing(): void
    {
        $this->fake->on(self::EXPORTS, new Response(202, [], (string) self::export(501, 'pending')->body));
        $this->fake->on(self::EXPORTS . '/501', self::export(501, 'failed', ['errorInfo' => 'no keys matched']));
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertStringContainsString('no keys matched', $result->message);
        self::assertSame([], $this->outFiles());
    }

    public function testGivesUpAtTheTimeout(): void
    {
        $this->fake->on(self::EXPORTS, new Response(202, [], (string) self::export(501, 'pending')->body));
        $this->fake->on(self::EXPORTS . '/501', self::export(501, 'processing'));
        $result = $this->pull(['timeout' => 30]);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertLessThanOrEqual(31, array_sum($this->clock->slept));
        self::assertSame([], $this->outFiles());
    }

    /** @return iterable<string, array{int}> */
    public function retried(): iterable
    {
        yield 'throttled' => [429];
        yield 'unavailable' => [503];
    }

    /** @dataProvider retried */
    public function testRetriesTheCreateOnlyWhenTheServerSaysTo(int $status): void
    {
        $created = self::export(501, 'completed');
        $this->fake->on(self::EXPORTS, [new Response($status, ['retry-after' => '7']), new Response(201, [], (string) $created->body)]);
        $this->fake->on(self::EXPORTS . '/501/download', new Response(200, [], self::laravel(['en/a.php' => 'x'])));
        $result = $this->pull();

        self::assertSame(ExportResult::UPDATED, $result->status, $result->message);
        self::assertContains(7, $this->clock->slept);
    }

    /** @return iterable<string, array{Response}> */
    public function notRetried(): iterable
    {
        yield 'a server error' => [new Response(500)];
        yield 'a gateway error' => [new Response(502)];
        yield 'no answer' => [new Response(0)];
    }

    /** @dataProvider notRetried */
    public function testNeverRetriesACreateThatMayHaveBeenAccepted(Response $answer): void
    {
        $this->fake->on(self::EXPORTS, [$answer, new Response(201, [], (string) self::export(501, 'completed')->body)]);
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertCount(1, $this->fake->requests);
    }

    public function testARetryAfterPastTheTimeoutGivesUpAtOnce(): void
    {
        $this->fake->on(self::EXPORTS, new Response(429, ['retry-after' => '600']));
        $result = $this->pull(['timeout' => 60]);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertCount(1, $this->fake->requests);
        self::assertSame([], $this->clock->slept);
    }

    /** @return iterable<string, array{int}> */
    public function refused(): iterable
    {
        foreach ([400, 401, 403, 404] as $status) {
            yield (string) $status => [$status];
        }
    }

    /** @dataProvider refused */
    public function testARefusedRequestIsMisconfigured(int $status): void
    {
        $this->fake->on(self::EXPORTS, new Response($status, [], '{"code":"x","detail":"' . self::API_KEY . '"}'));
        $result = $this->pull();

        self::assertSame(ExportResult::MISCONFIGURED, $result->status);
        self::assertStringNotContainsString(self::API_KEY, $result->message);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function badConfigs(): iterable
    {
        yield 'no api url' => [['apiUrl' => null]];
        yield 'plain http api url' => [['apiUrl' => 'http://api.example.test']];
        yield 'api url with a query' => [['apiUrl' => self::API . '?k=1']];
        yield 'no api key' => [['apiKey' => null]];
        yield 'api key with a line break' => [['apiKey' => self::API_KEY . "\r\nX: y"]];
    }

    /**
     * @dataProvider badConfigs
     * @param array<string, mixed> $overrides
     */
    public function testABadConfigIsMisconfiguredAndCallsNobody(array $overrides): void
    {
        $result = $this->pull([], $overrides);

        self::assertSame(ExportResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testARelativeTargetIsMisconfigured(): void
    {
        $result = $this->client()->exports()->pull(12, 34, 'lang', []);

        self::assertSame(ExportResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testAFailedDownloadChangesNothing(): void
    {
        $this->serveExport('x');
        $this->fake->on(self::EXPORTS . '/501/download', new Response(503));
        $result = $this->pull();

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
    }

    public function testDownloadsAreCapped(): void
    {
        $this->serveExport(self::laravel(['en/a.php' => str_repeat('x', 4096)]));
        $result = $this->pull(['maxBytes' => 1024]);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame([], $this->outFiles());
    }

    public function testNeverThrows(): void
    {
        $this->fake->on(self::EXPORTS, new Response(201, [], '{not json'));

        self::assertSame(ExportResult::FAILED, $this->pull()->status);
    }
}
