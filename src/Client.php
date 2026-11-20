<?php

declare(strict_types=1);

namespace Tarjim;

use Tarjim\Cycle\Refresher;
use Tarjim\Store\Disk;
use Tarjim\Store\Store;

final class Client
{
    /** @var Config */
    private $config;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Never throws: a bad config is reported by refresh() and the logger, and lookups answer with
     * the key text, so a page keeps rendering.
     *
     * @param array<string, mixed> $config
     */
    public static function create(array $config): self
    {
        $parsed = Config::fromArray($config);
        // The argument is part of this frame in an exception trace, and the array holds the key.
        $config = [];
        $problems = $parsed->problems();
        $client = new self($parsed);
        foreach ($problems as $problem) {
            $client->log('error', $problem);
        }
        if ($problems !== [] && $parsed->get('strict') === true) {
            self::refuse($problems);
        }

        return $client;
    }

    /** The key and the delivery URL stay out of print_r() and var_dump(). */
    public function __debugInfo(): array
    {
        return ['config' => $this->config->__debugInfo()];
    }

    /** @param array<string, mixed> $options */
    public function refresh(array $options = []): RefreshResult
    {
        try {
            $result = (new Refresher($this->config))->run($options);
        } catch (\Throwable $e) {
            $result = new RefreshResult(RefreshResult::FAILED, 'the refresh failed unexpectedly');
        }
        if ($result->status === RefreshResult::FAILED || $result->status === RefreshResult::MISCONFIGURED) {
            $this->log($result->status === RefreshResult::FAILED ? 'warning' : 'error', $result->message);
        }

        return $result;
    }

    /** Reads the store only: no network, no log, no exception. */
    public function status(): Status
    {
        $status = new Status();
        try {
            $dir = $this->config->get('dir');
            $disk = $this->config->get('disk');
            if (!is_string($dir) || !$disk instanceof Disk) {
                return $status;
            }
            $store = new Store($disk, $dir);
            $state = $store->readState();
            $held = $store->held();
            $status->lastCheckAt = $state->lastCheckAt;
            $status->lastSuccessAt = $state->lastSuccessAt;
            if ($held === null) {
                return $status;
            }
            $status->releaseId = $held->releaseId;
            $status->distribution = $held->distribution;
            $status->stage = $held->stage;
            $status->installedAt = $held->installedAt;
            $status->source = 'store';
            $clock = $this->config->get('now');
            $now = $clock === null ? time() : (int) $clock();
            $staleAfter = $this->config->get('staleAfter');
            // A success dated after now means the clock moved back; it proves nothing about freshness.
            $status->stale = $state->lastSuccessAt === null
                || $state->lastSuccessAt > $now
                || $now - $state->lastSuccessAt > (is_int($staleAfter) ? $staleAfter : 86400);
        } catch (\Throwable $e) {
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     */
    public function t(string $key, array $args = [], array $options = []): string
    {
        return $key;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     */
    public function tHtml(string $key, array $args = [], array $options = []): string
    {
        throw new \LogicException('not implemented');
    }

    /** @param array<string, mixed> $options */
    public function image(string $key, array $options = []): ?Image
    {
        throw new \LogicException('not implemented');
    }

    /** @param array<string, mixed> $options */
    public function has(string $key, array $options = []): bool
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function locales(array $options = []): array
    {
        throw new \LogicException('not implemented');
    }

    public function exports(): Export\Exports
    {
        throw new \LogicException('not implemented');
    }

    /**
     * Takes only the problem list, so no frame of the exception's trace holds the config array
     * (and the key in it).
     *
     * @param list<string> $problems
     * @return never
     */
    private static function refuse(array $problems): void
    {
        throw new \InvalidArgumentException('Invalid Tarjim config: ' . implode('; ', $problems));
    }

    /** A logger that is missing, not callable or throws must never break the page. */
    private function log(string $level, string $message): void
    {
        $logger = $this->config->get('logger');
        if (!is_callable($logger)) {
            return;
        }
        try {
            $logger($level, $message, []);
        } catch (\Throwable $ignored) {
        }
    }
}
