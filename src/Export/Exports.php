<?php

declare(strict_types=1);

namespace Tarjim\Export;

use Tarjim\Config;
use Tarjim\Store\Disk;
use Tarjim\Version;
use Tarjim\Wire\DeliveryUrl;
use Tarjim\Wire\Request;
use Tarjim\Wire\Response;
use Tarjim\Wire\Transport;

final class Exports
{
    private const DEFAULT_TIMEOUT = 300;
    private const DEFAULT_POLL = 2;
    private const DEFAULT_MAX_BYTES = 64 * 1024 * 1024;
    private const DEFAULT_RETRY_AFTER = 5;
    private const MAX_CREATE_ATTEMPTS = 10;
    private const JSON_LIMIT = 1048576;
    // The server holds a create call open while the job runs, so silence on it is not a stall yet.
    private const CREATE_STALL = 60;
    private const STATUSES = ['pending', 'processing', 'completed', 'failed'];

    /** @var Config */
    private $config;

    /** @var callable(string, string): void */
    private $log;

    /** @var int|null the export the running pull has been given by the server */
    private $exportId;

    /** @param callable(string, string): void $log */
    public function __construct(Config $config, callable $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * Asks the API for an export of a bundle, waits for it, and installs it into $intoDir.
     * Never throws. $options: timeout (seconds, default 300), poll (seconds, default 2) and
     * maxBytes (default 64 MiB), which bounds the download and the unpacked size alike, and
     * adoptExisting (default false), which lets the pull take over files that are already in the
     * target and that an earlier pull did not write.
     *
     * @param array<string, mixed> $options
     */
    public function pull(int $projectId, int $bundleId, string $intoDir, array $options = []): ExportResult
    {
        $this->exportId = null;
        try {
            $installed = $this->run($projectId, $bundleId, $intoDir, $options);
            $result = new ExportResult(ExportResult::UPDATED);
            [$result->written, $result->removed, $notes] = $installed;
            $result->message = 'wrote ' . count($result->written) . ' file(s), removed ' . count($result->removed)
                . ($notes === [] ? '' : '; ' . implode('; ', $notes));
        } catch (Refusal $e) {
            $result = new ExportResult($e->status);
            $result->message = $e->getMessage();
        } catch (\Throwable $e) {
            $result = new ExportResult(ExportResult::FAILED);
            $result->message = 'the export failed unexpectedly';
        }
        $result->exportId = $this->exportId;
        if ($result->status !== ExportResult::UPDATED) {
            ($this->log)($result->status === ExportResult::FAILED ? 'warning' : 'error', $result->message);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{list<string>, list<string>, list<string>}
     * @throws Refusal
     */
    private function run(int $projectId, int $bundleId, string $intoDir, array $options): array
    {
        [$apiUrl, $headers] = $this->setup($projectId, $bundleId, $intoDir);
        $target = rtrim($intoDir, '/');
        Installer::sweep($target, $this->now());
        $adopt = ($options['adoptExisting'] ?? false) === true;
        $timeout = self::positive($options['timeout'] ?? null, self::DEFAULT_TIMEOUT);
        $poll = self::positive($options['poll'] ?? null, self::DEFAULT_POLL);
        $maxBytes = self::positive($options['maxBytes'] ?? null, self::DEFAULT_MAX_BYTES);
        $deadline = $this->now() + $timeout;
        $base = $apiUrl . '/projects/' . $projectId . '/exports';

        $export = $this->create($base, $headers, $bundleId, $deadline);
        $exportId = $this->exportId = $export['id'];
        while ($export['status'] !== 'completed') {
            if ($export['status'] === 'failed') {
                throw new Refusal('the export failed on the server' . self::detail($export['errorInfo'] ?? null));
            }
            if ($this->now() + $poll > $deadline) {
                throw new Refusal('the export did not finish within ' . $timeout . ' seconds');
            }
            $this->sleep($poll);
            $export = $this->poll($base . '/' . $exportId, $headers) ?? $export;
        }

        $download = $this->send(new Request($base . '/' . $exportId . '/download', $headers, $maxBytes));
        if ($download->tooLarge) {
            throw new Refusal('the download is larger than maxBytes');
        }
        if ($download->status !== 200 || $download->body === null) {
            throw new Refusal('the export could not be downloaded' . self::statusNote($download));
        }
        $bytes = $download->body;

        $disk = $this->config->get('disk');
        if (!$disk instanceof Disk) {
            throw new Refusal('no disk is available');
        }
        $installer = new Installer($disk);
        if (strncmp($bytes, "PK\x03\x04", 4) === 0 || strncmp($bytes, "PK\x05\x06", 4) === 0) {
            $archive = Archive::open($bytes, $maxBytes);
            try {
                $paths = $archive->paths();
                if ($paths === []) {
                    throw new Refusal('the export holds no files');
                }

                return $installer->install($target, $paths, static function (string $path, string $to) use ($archive): void {
                    $archive->copy($path, $to);
                }, $export['id'], $adopt);
            } finally {
                $archive->close();
            }
        }

        $name = self::singleFileName($export, $download);
        // A body that is not a zip under a zip's name is a broken download, not a file to install.
        if (preg_match('/\.zip$/i', $name) === 1) {
            throw new Refusal('the download is not a valid zip');
        }

        return $installer->install($target, [$name], static function (string $path, string $to) use ($bytes): void {
            if (@file_put_contents($to, $bytes) !== strlen($bytes)) {
                throw new Refusal('the export could not be written (is the disk full?)');
            }
        }, $export['id'], $adopt);
    }

    /**
     * @return array{string, array<string, string>} the API URL and the headers every call carries
     * @throws Refusal
     */
    private function setup(int $projectId, int $bundleId, string $intoDir): array
    {
        $apiUrl = $this->config->get('apiUrl');
        $apiKey = $this->config->get('apiKey');
        if (!is_string($apiUrl)) {
            throw self::misconfigured('apiUrl is required');
        }
        $why = DeliveryUrl::problem($apiUrl, 'apiUrl');
        if ($why !== null) {
            throw self::misconfigured($why);
        }
        if (!is_string($apiKey) || $apiKey === '') {
            throw self::misconfigured('apiKey is required');
        }
        // Whitespace or a control character would let the key add a header line to the request.
        if (preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
            throw self::misconfigured('apiKey is malformed');
        }
        if ($projectId < 1 || $bundleId < 1) {
            throw self::misconfigured('the project and the bundle must be positive ids');
        }
        if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $intoDir) !== 1 || strpos($intoDir, "\0") !== false || rtrim($intoDir, '/') === '') {
            throw self::misconfigured('the target folder must be an absolute path');
        }

        return [$apiUrl, ['X-Tarjim-Apikey' => $apiKey, 'X-Tarjim-Api-Version' => Version::API]];
    }

    private static function misconfigured(string $message): Refusal
    {
        return new Refusal($message, ExportResult::MISCONFIGURED);
    }

    /**
     * Retries only when the server says the call was not accepted (429, 503). Any other failure may
     * have left a created export behind, and a second call would make another one.
     *
     * @param array<string, string> $headers
     * @return array{id: int, status: string, fileName?: mixed, errorInfo?: mixed}
     * @throws Refusal
     */
    private function create(string $url, array $headers, int $bundleId, int $deadline): array
    {
        $body = (string) json_encode(['customBundleId' => $bundleId]);
        for ($attempt = 1;; $attempt++) {
            $response = $this->send(new Request($url, $headers + ['Content-Type' => 'application/json'], self::JSON_LIMIT, 'POST', $body, self::CREATE_STALL));
            if ($response->status >= 200 && $response->status < 300) {
                $export = self::decode($response);
                if ($export === null) {
                    throw new Refusal('the export API answered with something unreadable');
                }

                return $export;
            }
            if ($response->status === 429 || $response->status === 503) {
                $wait = max(1, self::retryAfter($response));
                if ($attempt >= self::MAX_CREATE_ATTEMPTS || $this->now() + $wait > $deadline) {
                    throw new Refusal('the export API is busy (HTTP ' . $response->status . ') and did not accept the request in time');
                }
                $this->sleep($wait);
                continue;
            }
            if (in_array($response->status, [400, 401, 403, 404], true)) {
                throw new Refusal('the export API refused the request (HTTP ' . $response->status . '): check apiKey, the project and the bundle', ExportResult::MISCONFIGURED);
            }
            throw new Refusal('the export could not be created' . self::statusNote($response));
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{id: int, status: string, fileName?: mixed, errorInfo?: mixed}|null null to try again at the next interval
     * @throws Refusal
     */
    private function poll(string $url, array $headers): ?array
    {
        $response = $this->send(new Request($url, $headers, self::JSON_LIMIT));
        if (in_array($response->status, [400, 401, 403, 404], true)) {
            throw new Refusal('the export could not be followed' . self::statusNote($response));
        }

        return $response->status === 200 ? self::decode($response) : null;
    }

    private function send(Request $request): Response
    {
        $transport = $this->config->get('transport');
        if (!$transport instanceof Transport) {
            return Response::none();
        }

        return $transport->send($request);
    }

    /** @return array{id: int, status: string, fileName?: mixed, errorInfo?: mixed}|null */
    private static function decode(Response $response): ?array
    {
        $data = $response->body === null ? null : json_decode($response->body, true);
        if (!is_array($data) || !isset($data['id'], $data['status']) || !is_int($data['id']) || $data['id'] < 1
            || !is_string($data['status']) || !in_array($data['status'], self::STATUSES, true)) {
            return null;
        }

        return $data;
    }

    private static function retryAfter(Response $response): int
    {
        $value = trim((string) $response->header('retry-after'));

        return preg_match('/^[0-9]{1,6}$/', $value) === 1 ? (int) $value : self::DEFAULT_RETRY_AFTER;
    }

    /**
     * @param array<string, mixed> $export
     * @throws Refusal
     */
    private static function singleFileName(array $export, Response $download): string
    {
        $name = $export['fileName'] ?? null;
        if (!is_string($name)) {
            $disposition = $download->header('content-disposition');
            $name = $disposition !== null && preg_match('/filename\s*=\s*"?([^";]*)"?/i', $disposition, $m) === 1 ? $m[1] : '';
        }
        if (!Paths::isBasename($name)) {
            throw new Refusal('the export has no usable file name');
        }

        return $name;
    }

    /** The server's own words, trimmed: they end up in a terminal and a log. */
    /** @param mixed $info */
    private static function detail($info): string
    {
        if (!is_string($info) || trim($info) === '') {
            return '';
        }
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $info);

        return ': ' . (function_exists('mb_substr') ? mb_substr($clean, 0, 300) : substr($clean, 0, 300));
    }

    private static function statusNote(Response $response): string
    {
        return $response->status === 0 ? ' (no answer)' : ' (HTTP ' . $response->status . ')';
    }

    /** @param mixed $value */
    private static function positive($value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function now(): int
    {
        $clock = $this->config->get('now');

        return is_callable($clock) ? (int) $clock() : time();
    }

    private function sleep(int $seconds): void
    {
        $sleep = $this->config->get('sleep');
        if (is_callable($sleep)) {
            $sleep($seconds);
        } else {
            \sleep($seconds);
        }
    }
}
