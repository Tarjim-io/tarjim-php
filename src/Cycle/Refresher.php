<?php

declare(strict_types=1);

namespace Tarjim\Cycle;

use Tarjim\Config;
use Tarjim\Lookup\BundleChoice;
use Tarjim\RefreshResult;
use Tarjim\Store\Disk;
use Tarjim\Store\HeldCopy;
use Tarjim\Store\Lock;
use Tarjim\Store\State;
use Tarjim\Store\Store;
use Tarjim\Wire\DeliveryClient;
use Tarjim\Wire\Meta;
use Tarjim\Wire\Outcome;

/** One refresh: lock, cadence, meta, manifest, missing files, install, prune. */
final class Refresher
{
    /** Seconds between two meta reads on one store, across every process sharing it. */
    private const FLOOR = 60;
    /** A wait further ahead than this is a corrupt or hostile state file, not a Retry-After. */
    private const LONGEST_WAIT = 86400;
    private const CLOCK_SLACK = 86400;
    private const BUSY_STEP = 1;
    private const UNWRITTEN = 'the store state could not be written';

    /** @var Config */
    private $config;

    /** @var callable(string, string): void */
    private $log;

    /** @param callable(string, string): void $log */
    public function __construct(Config $config, callable $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /** @param array<string, mixed> $options */
    public function run(array $options): RefreshResult
    {
        $store = null;
        try {
            $problems = $this->config->refreshProblems();
            $store = $this->store();
            if ($problems !== []) {
                return $this->result(RefreshResult::MISCONFIGURED, implode('; ', $problems), null, $store);
            }
            if ($store === null) {
                return new RefreshResult(RefreshResult::MISCONFIGURED, 'dir is not set');
            }
            $problem = $store->prepare();
            if ($problem !== null) {
                return $this->result(RefreshResult::MISCONFIGURED, $problem, null, $store);
            }

            $wait = $options['wait'] ?? 0;
            $wait = is_int($wait) || (is_string($wait) && ctype_digit($wait)) ? max(0, (int) $wait) : 0;
            $deadline = $this->now() + $wait;
            while (true) {
                [$result, $delay] = $this->attempt($store);
                if ($wait <= 0 || $delay === null || $this->now() + $delay > $deadline) {
                    return $result;
                }
                $this->sleep($delay);
            }
        } catch (\Throwable $e) {
            // The class only: a message could carry a path or a URL.
            return $this->result(RefreshResult::FAILED, 'the refresh failed unexpectedly (' . get_class($e) . ')', null, $store);
        }
    }

    /** @return array{0: RefreshResult, 1: int|null} the result and how long until it could differ */
    private function attempt(Store $store): array
    {
        $lock = Lock::open($store->lockPath());
        if ($lock === null) {
            return [$this->result(RefreshResult::MISCONFIGURED, 'the store lock file cannot be opened', null, $store), null];
        }
        if (!$lock->tryLock()) {
            $lock->release();

            return [$this->result(RefreshResult::BUSY, 'another refresh is running', null, $store), self::BUSY_STEP];
        }
        try {
            return $this->locked($store);
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: RefreshResult, 1: int|null} */
    private function locked(Store $store): array
    {
        $now = $this->now();
        $state = $store->readState();
        $held = $store->held();

        $due = $now;
        // A recorded time ahead of now means the clock moved back; trusting it would stop checks.
        if ($state->lastCheckAt !== null && $state->lastCheckAt <= $now) {
            $due = max($due, $state->lastCheckAt + self::FLOOR);
        }
        // A wait recorded at the cap and read after the clock stepped back by up to a day is still a
        // real wait, so it is clamped; anything further out is corrupt and ignored.
        if ($state->notBefore !== null && $state->notBefore - $now <= self::LONGEST_WAIT + self::CLOCK_SLACK) {
            $due = max($due, min($state->notBefore, $now + self::LONGEST_WAIT));
        }
        if ($due > $now) {
            $wait = $due - $now;

            return [$this->result(RefreshResult::NOT_DUE, 'the next check is not due yet', $wait, $store), $wait];
        }

        // Recorded before Tarjim is called, so a refresh killed half way still counts against the floor.
        $state->lastCheckAt = $now;
        if (!$store->writeState($state)) {
            return [$this->result(RefreshResult::FAILED, self::UNWRITTEN, null, $store), null];
        }

        $delivery = new DeliveryClient($this->config->get('transport'), (string) $this->config->get('deliveryUrl'), (string) $this->config->get('key'));
        $etag = $held !== null && $state->etagFor === $held->releaseId . '-' . $held->checksum ? $state->etag : null;
        $outcome = $delivery->fetchMeta($held === null ? null : $held->releaseId, $etag);

        switch ($outcome->kind) {
            case Outcome::NOT_MODIFIED:
                if ($held === null) {
                    return [$this->failed($store, $state, $now, null, 'Tarjim answered "not modified" to a request with no copy held'), null];
                }

                return [$this->unchanged($store, $state, $now, $held), null];
            case Outcome::UNRELEASED:
                return [$this->unreleased($store, $state, $now), self::FLOOR];
            case Outcome::CONFIGURATION:
                $why = 'Tarjim refused the delivery URL or key (HTTP ' . (int) $outcome->status . ($outcome->code !== null ? ', ' . $outcome->code : '') . ')';

                return [$this->result(RefreshResult::MISCONFIGURED, $why, null, $store), null];
            case Outcome::RECEIVED:
                if ($outcome->meta === null) {
                    return [$this->failed($store, $state, $now, null, 'the answer from Tarjim could not be read'), null];
                }

                return [$this->received($store, $delivery, $state, $now, $held, $outcome->meta), null];
            default:
                return [$this->failed($store, $state, $now, $outcome->retryAfter, self::describe($outcome)), null];
        }
    }

    private function received(Store $store, DeliveryClient $delivery, State $state, int $now, ?HeldCopy $held, Meta $meta): RefreshResult
    {
        // A copy written before the binding was recorded has none to compare, and adopts the meta's.
        if ($held !== null
            && (($held->distribution !== null && $held->distribution !== $meta->distribution)
                || ($held->stage !== null && $held->stage !== $meta->stage))) {
            return $this->result(RefreshResult::MISCONFIGURED, 'the store holds another distribution or stage than the delivery URL names', null, $store);
        }
        if ($held !== null && $held->releaseId === $meta->releaseId && $held->checksum === $meta->checksum) {
            $state->etag = $meta->etag;
            $state->etagFor = $meta->releaseId . '-' . $meta->checksum;

            return $this->unchanged($store, $state, $now, $held);
        }
        if ($meta->schemaVersion !== 1) {
            return $this->failed($store, $state, $now, null, 'Tarjim serves a format this client does not know');
        }

        $manifest = $delivery->fetchManifest($meta);
        if ($manifest->kind !== Outcome::VERIFIED || $manifest->manifest === null) {
            return $this->failed($store, $state, $now, $manifest->retryAfter, self::describe($manifest));
        }
        if ($manifest->manifest->schemaVersion !== 1) {
            return $this->failed($store, $state, $now, null, 'the release uses a format this client does not know');
        }

        // Checked before any file is fetched: a bundle that is missing or nested would otherwise show
        // key text on every page while this refresh reported success.
        $problem = $this->bundleProblem($manifest->manifest->bundles);
        if ($problem !== null) {
            return $this->result(RefreshResult::MISCONFIGURED, $problem, null, $store);
        }

        // Every bundle is recorded so a lookup picks by the same rule as this install, but only flat
        // ones are read, so only their files are fetched.
        $index = [];
        $needed = [];
        $skipped = [];
        foreach ($manifest->manifest->bundles as $id => $bundle) {
            $index[$id] = ['type' => $bundle['type'], 'name' => $bundle['name'], 'layout' => $bundle['layout'], 'languages' => []];
            if (!BundleChoice::isFlat($bundle['layout'])) {
                $skipped[$bundle['name']] = $bundle['name'];
                continue;
            }
            foreach ($manifest->manifest->files[$id] ?? [] as $language => $file) {
                $index[$id]['languages'][$language] = $file->hash;
                $needed[$file->hash] = $file;
            }
        }
        if ($needed === []) {
            return $this->failed($store, $state, $now, null, 'the release has no file this client can use');
        }

        $missing = [];
        foreach ($needed as $hash => $file) {
            if (!$store->hasObject((string) $hash)) {
                $missing[] = $file;
            }
        }
        $verified = [];
        if ($missing !== []) {
            $fetched = $delivery->fetchFiles($manifest->meta ?? $meta, $missing);
            foreach ($missing as $file) {
                $one = $fetched->results[$file->hash] ?? null;
                if ($one === null || $one->kind !== Outcome::VERIFIED || $one->bytes === null || !hash_equals($file->hash, hash('sha256', $one->bytes))) {
                    return $this->failed($store, $state, $now, $one === null ? null : $one->retryAfter, 'a file of the release could not be fetched and verified');
                }
                $verified[$file->hash] = $one->bytes;
            }
        }

        // Objects, then the index, then the pointer: until the pointer moves readers see the old release.
        foreach ($verified as $hash => $bytes) {
            if (!$store->putObject((string) $hash, $bytes)) {
                return $this->failed($store, $state, $now, null, 'the store could not be written');
            }
        }
        $name = Store::indexName($meta->releaseId, $meta->checksum);
        $document = [
            'releaseId' => $meta->releaseId,
            'checksum' => $meta->checksum,
            'distribution' => $meta->distribution,
            'stage' => $meta->stage,
            'bundles' => $index,
        ];
        $installed = new HeldCopy(
            $meta->releaseId,
            $meta->checksum,
            $meta->distribution,
            $meta->stage,
            $now,
            $name,
            $held === null ? null : $held->index
        );
        if (!$store->putIndex($name, $document) || !$store->putCurrent($installed)) {
            return $this->failed($store, $state, $now, null, 'the store could not be written');
        }

        $state->lastSuccessAt = $now;
        $state->notBefore = null;
        $state->etag = $meta->etag;
        $state->etagFor = $meta->releaseId . '-' . $meta->checksum;
        if (!$store->writeState($state)) {
            return $this->result(RefreshResult::FAILED, self::UNWRITTEN, null, $store);
        }
        $store->prune($now, $installed);
        if ($skipped !== []) {
            $this->warn('the release has bundles this client does not read, because they are not flat: ' . implode(', ', $skipped));
        }

        return $this->result(RefreshResult::UPDATED, 'release ' . $meta->releaseId . ' installed', null, $store);
    }

    /**
     * @param array<string, array{type: string, name: string, layout: string|null}> $bundles
     * @return string|null what is wrong with the configured bundle in this release
     */
    private function bundleProblem(array $bundles): ?string
    {
        $configured = $this->config->get('bundle');
        $name = is_string($configured) && $configured !== '' ? $configured : 'default';
        $id = BundleChoice::find($bundles, $name);
        if ($id === null) {
            return "the configured bundle '" . $name . "' is not in the release";
        }
        $layout = $bundles[$id]['layout'];
        if (!BundleChoice::isFlat($layout)) {
            return "the configured bundle '" . $name . "' " . ($layout === 'nested' ? 'is nested; create it as a flat bundle' : 'must be a flat bundle');
        }

        return null;
    }

    private function unreleased(Store $store, State $state, int $now): RefreshResult
    {
        $state->lastSuccessAt = $now;
        $state->notBefore = null;
        if (!$store->writeState($state)) {
            return $this->result(RefreshResult::FAILED, self::UNWRITTEN, null, $store);
        }

        return $this->result(RefreshResult::UNRELEASED, 'the stage has no release yet', null, $store);
    }

    private function unchanged(Store $store, State $state, int $now, HeldCopy $held): RefreshResult
    {
        $state->lastSuccessAt = $now;
        $state->notBefore = null;
        if (!$store->writeState($state)) {
            return $this->result(RefreshResult::FAILED, self::UNWRITTEN, null, $store);
        }
        $store->prune($now, $held);

        return $this->result(RefreshResult::UNCHANGED, 'release ' . $held->releaseId . ' is current', null, $store);
    }

    private function failed(Store $store, State $state, int $now, ?int $retryAfter, string $message): RefreshResult
    {
        $wait = max(self::FLOOR, $retryAfter ?? 0);
        $state->notBefore = $now + $wait;
        // Without the record the wait is not kept, so none is promised.
        $kept = $store->writeState($state);

        return $this->result(RefreshResult::FAILED, $message, $kept ? $wait : null, $store);
    }

    private static function describe(Outcome $outcome): string
    {
        switch ($outcome->kind) {
            case Outcome::THROTTLED:
                return 'Tarjim is limiting this client';
            case Outcome::SERVER:
                return 'Tarjim answered with a server error (HTTP ' . (int) $outcome->status . ')';
            case Outcome::NETWORK:
                return 'Tarjim could not be reached';
            case Outcome::UNREADABLE:
                return 'the answer from Tarjim could not be read';
            case Outcome::CHECKSUM_MISMATCH:
                return 'the release manifest did not match its checksum';
            default:
                return 'the release manifest could not be fetched and verified';
        }
    }

    private function result(string $status, string $message, ?int $retryAfter, ?Store $store): RefreshResult
    {
        $held = false;
        try {
            $held = $store !== null && $store->held() !== null;
        } catch (\Throwable $e) {
        }
        $key = $this->config->get('key');
        if (is_string($key) && $key !== '') {
            $message = str_replace($key, '***', $message);
        }

        return new RefreshResult($status, $message, $retryAfter, $held);
    }

    private function store(): ?Store
    {
        $dir = $this->config->get('dir');

        $disk = $this->config->get('disk');

        return is_string($dir) && $disk instanceof Disk ? new Store($disk, $dir) : null;
    }

    /** A logger that throws must never fail a refresh that has already installed. */
    private function warn(string $message): void
    {
        try {
            ($this->log)('warning', $message);
        } catch (\Throwable $ignored) {
        }
    }

    private function now(): int
    {
        $clock = $this->config->get('now');

        return $clock === null ? time() : (int) $clock();
    }

    private function sleep(int $seconds): void
    {
        $sleep = $this->config->get('sleep');
        if ($sleep === null) {
            \sleep($seconds);

            return;
        }
        $sleep($seconds);
    }
}
