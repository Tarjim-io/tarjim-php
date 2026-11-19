<?php

declare(strict_types=1);

namespace Tarjim\Wire;

use Tarjim\Version;

final class DeliveryClient
{
    private const META_LIMIT = 1048576;
    private const MANIFEST_LIMIT = 8388608;
    private const RETRY_AFTER_CAP = 86400;
    // A signed address that has expired answers like this; a fresh meta carries a new signature.
    private const STALE_SIGNATURE = [403, 404, 503];
    private const CONFIGURATION = [400, 401, 403, 404];

    /** @var Transport */
    private $transport;

    /** @var string */
    private $deliveryUrl;

    /** @var string */
    private $key;

    /** @var string|null the release the caller holds, as of the last fetchMeta() */
    private $heldReleaseId;

    public function __construct(Transport $transport, string $deliveryUrl, string $key)
    {
        $this->transport = $transport;
        $this->deliveryUrl = $deliveryUrl;
        $this->key = $key;
    }

    /** Nothing secret: the delivery URL may carry a key and the key itself must stay out of dumps. */
    public function __debugInfo(): array
    {
        return ['transport' => get_class($this->transport)];
    }

    public function fetchMeta(?string $heldReleaseId = null, ?string $heldEtag = null): Outcome
    {
        $this->heldReleaseId = $heldReleaseId;
        try {
            $url = $this->deliveryUrl . '?platform=php&sdkVersion=' . rawurlencode(Version::SDK)
                . '&release=' . rawurlencode($heldReleaseId === null || $heldReleaseId === '' ? '0' : $heldReleaseId);
            $headers = $this->keyHeaders();
            if ($heldEtag !== null && $heldEtag !== '') {
                $headers['If-None-Match'] = $heldEtag;
            }
            $response = $this->get(new Request($url, $headers, self::META_LIMIT));
            $status = $response->status;

            if ($status === 200) {
                return $response->tooLarge || $response->body === null ? new Outcome(Outcome::UNREADABLE) : $this->decodeMeta($response);
            }
            if ($status === 304) {
                return new Outcome(Outcome::NOT_MODIFIED);
            }
            if (in_array($status, self::CONFIGURATION, true)) {
                $code = self::problemCode($response);
                if ($status === 404 && $code === 'delivery.stage_unreleased') {
                    return new Outcome(Outcome::UNRELEASED);
                }
                $outcome = new Outcome(Outcome::CONFIGURATION);
                $outcome->status = $status;
                $outcome->code = $code;

                return $outcome;
            }

            return self::failure($response, false);
        } catch (\Throwable $e) {
            return new Outcome(Outcome::NETWORK);
        }
    }

    public function fetchManifest(Meta $meta): Outcome
    {
        try {
            $outcome = $this->readManifest($meta);
            if ($meta->authenticated || !self::mayBeStaleSignature($outcome)) {
                return $outcome;
            }

            // One re-read: an expired signature is the only thing it can fix. It names the release the
            // caller holds, not the one being fetched, and sends no validator.
            $again = $this->fetchMeta($this->heldReleaseId);
            if ($again->kind !== Outcome::RECEIVED || $again->meta === null || $again->meta->checksum !== $meta->checksum) {
                return $outcome;
            }
            $retry = $this->readManifest($again->meta);
            $retry->meta = $again->meta;

            return $retry;
        } catch (\Throwable $e) {
            return new Outcome(Outcome::NETWORK);
        }
    }

    /** @param list<FileRef> $files */
    public function fetchFiles(Meta $meta, array $files): FilesResult
    {
        try {
            $unique = [];
            foreach ($files as $file) {
                if (!isset($unique[$file->hash])) {
                    $unique[$file->hash] = $file;
                }
            }
            $results = [];
            $stale = [];
            foreach ($unique as $hash => $file) {
                $outcome = $this->readFile($meta, $file);
                $results[$hash] = $outcome;
                if (!$meta->authenticated && self::mayBeStaleSignature($outcome)) {
                    $stale[] = $file;
                }
            }
            if ($stale === []) {
                return new FilesResult($results, $meta, false);
            }

            $again = $this->fetchMeta($this->heldReleaseId);
            if ($again->kind !== Outcome::RECEIVED || $again->meta === null || $again->meta->checksum !== $meta->checksum) {
                return new FilesResult($results, $meta, true);
            }
            $fresh = $again->meta;
            // The whole re-read meta is used: the base and the mode can move together with the signature.
            if ($fresh->signedQuery !== $meta->signedQuery || $fresh->slicesBaseUrl !== $meta->slicesBaseUrl || $fresh->authenticated !== $meta->authenticated) {
                foreach ($stale as $file) {
                    $results[$file->hash] = $this->readFile($fresh, $file);
                }
            }

            return new FilesResult($results, $fresh, true);
        } catch (\Throwable $e) {
            return new FilesResult([], $meta, false);
        }
    }

    private function readManifest(Meta $meta): Outcome
    {
        $target = $this->target($meta, $meta->manifestUrl);
        if ($target === null) {
            return new Outcome(Outcome::REFUSED);
        }
        $response = $this->get(new Request($target[0], $target[1], self::MANIFEST_LIMIT));
        if ($response->status !== 200) {
            return self::failure($response, true);
        }
        if ($response->tooLarge || $response->body === null) {
            return new Outcome(Outcome::UNREADABLE);
        }
        if (!hash_equals($meta->checksum, hash('sha256', $response->body))) {
            return new Outcome(Outcome::CHECKSUM_MISMATCH);
        }
        $manifest = Manifest::decode($response->body);
        if ($manifest === null) {
            return new Outcome(Outcome::UNREADABLE);
        }
        $outcome = new Outcome(Outcome::VERIFIED);
        $outcome->manifest = $manifest;
        $outcome->bytes = $response->body;
        $outcome->meta = $meta;

        return $outcome;
    }

    private function readFile(Meta $meta, FileRef $file): Outcome
    {
        // Without the slash the hash would run into the last path segment, or the host.
        $base = $meta->slicesBaseUrl;
        $target = preg_match('/^[0-9a-f]{64}\z/', $file->hash) === 1 && substr($base, -1) === '/'
            ? $this->target($meta, $base . $file->hash . '.json')
            : null;
        if ($target === null) {
            return new Outcome(Outcome::REFUSED);
        }
        $response = $this->get(new Request($target[0], $target[1], max(0, $file->size)));
        if ($response->status !== 200) {
            return self::failure($response, true);
        }
        if ($response->tooLarge || $response->body === null || strlen($response->body) !== $file->size) {
            return new Outcome(Outcome::SIZE_MISMATCH);
        }
        if (!hash_equals($file->hash, hash('sha256', $response->body))) {
            return new Outcome(Outcome::HASH_MISMATCH);
        }
        $outcome = new Outcome(Outcome::VERIFIED);
        $outcome->bytes = $response->body;

        return $outcome;
    }

    /** @return array{0: string, 1: array<string, string>}|null */
    private function target(Meta $meta, string $reference): ?array
    {
        $url = ObjectUrl::forMeta($this->deliveryUrl, $meta, $reference);
        if ($url === null) {
            return null;
        }

        // CDN mode sends no custom header, so the request stays a simple one and the key never leaves the origin.
        return [$url, $meta->authenticated ? $this->keyHeaders() : []];
    }

    /** @return array<string, string> */
    private function keyHeaders(): array
    {
        return ['X-Tarjim-Apikey' => $this->key, 'X-Tarjim-Api-Version' => Version::API];
    }

    private function get(Request $request): Response
    {
        try {
            return $this->transport->send($request);
        } catch (\Throwable $e) {
            return Response::none();
        }
    }

    private function decodeMeta(Response $response): Outcome
    {
        $m = json_decode((string) $response->body, true);
        if (!is_array($m)) {
            return new Outcome(Outcome::UNREADABLE);
        }
        $checksum = $m['checksum'] ?? null;
        $manifestUrl = $m['manifestUrl'] ?? null;
        $slicesBaseUrl = $m['slicesBaseUrl'] ?? null;
        $schemaVersion = $m['schemaVersion'] ?? null;
        $signedQuery = $m['signedQuery'] ?? null;
        $authenticated = ($m['authenticated'] ?? null) === true;
        $releaseId = self::releaseId($m['releaseId'] ?? null);
        if (
            !is_string($checksum) || preg_match('/^[0-9a-f]{64}\z/', $checksum) !== 1
            || !is_string($manifestUrl) || !is_string($slicesBaseUrl)
            || !is_int($schemaVersion) || $releaseId === null
            || !($authenticated || (is_string($signedQuery) && $signedQuery !== ''))
        ) {
            return new Outcome(Outcome::UNREADABLE);
        }
        $distribution = $m['distribution'] ?? null;
        $stage = $m['stage'] ?? null;
        // The binding a store is checked against: a meta without it cannot be trusted to install.
        if (!is_string($distribution) || $distribution === '' || ($stage !== 'dev' && $stage !== 'prod')) {
            return new Outcome(Outcome::UNREADABLE);
        }
        $outcome = new Outcome(Outcome::RECEIVED);
        $outcome->meta = new Meta(
            $checksum,
            $schemaVersion,
            $manifestUrl,
            $slicesBaseUrl,
            $authenticated,
            is_string($signedQuery) ? $signedQuery : null,
            $releaseId,
            $distribution,
            $stage,
            self::etag($response->header('etag'))
        );

        return $outcome;
    }

    /**
     * The id is built into the path of later requests by callers, so only digits may pass.
     *
     * @param mixed $value
     */
    private static function releaseId($value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        // A leading zero would name a different path than the number it spells.
        return is_string($value) && preg_match('/^(0|[1-9][0-9]*)\z/', $value) === 1 ? $value : null;
    }

    /** Kept only if it can be sent back as If-None-Match without breaking the header. */
    private static function etag(?string $etag): ?string
    {
        return $etag !== null && preg_match('/^(W\/)?"[\x21\x23-\x7E]{0,250}"\z/', $etag) === 1 ? $etag : null;
    }

    /** Nothing else from an error body is kept: the key may be echoed in its detail. */
    private static function problemCode(Response $response): ?string
    {
        $body = $response->tooLarge || $response->body === null ? null : json_decode($response->body, true);
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z0-9._\-]{1,128}\z/', $code) === 1 ? $code : null;
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = trim((string) $response->header('retry-after'), " \t");
        if (preg_match('/^[0-9]+\z/', $value) !== 1) {
            return self::retryAfterDate($value);
        }
        $digits = ltrim($value, '0');
        // Too many digits for an int is still a wait: cap it rather than drop it.
        if (strlen($digits) > 7) {
            return self::RETRY_AFTER_CAP;
        }

        return min((int) $digits, self::RETRY_AFTER_CAP);
    }

    /** Only the RFC 7231 fixed-length date; strtotime alone would accept far more than a server sends. */
    private static function retryAfterDate(string $value): ?int
    {
        $pattern = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/';
        if (preg_match($pattern, $value) !== 1) {
            return null;
        }
        $at = strtotime($value);

        return $at === false ? null : max(0, min($at - time(), self::RETRY_AFTER_CAP));
    }

    /** A non-success answer; $unfetchable is for objects, whose 4xx and 503 are not a configuration problem. */
    private static function failure(Response $response, bool $unfetchable): Outcome
    {
        $status = $response->status;
        if ($status === 0) {
            return new Outcome(Outcome::NETWORK);
        }
        if ($status === 429) {
            $kind = Outcome::THROTTLED;
        } elseif ($unfetchable && ($status === 503 || ($status >= 400 && $status < 500))) {
            $kind = Outcome::UNFETCHABLE;
        } else {
            $kind = Outcome::SERVER;
        }
        $outcome = new Outcome($kind);
        $outcome->status = $status;
        $outcome->retryAfter = self::retryAfter($response);

        return $outcome;
    }

    private static function mayBeStaleSignature(Outcome $outcome): bool
    {
        return $outcome->kind === Outcome::NETWORK
            || ($outcome->kind === Outcome::UNFETCHABLE && in_array($outcome->status, self::STALE_SIGNATURE, true));
    }
}
