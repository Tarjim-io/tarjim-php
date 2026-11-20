<?php

declare(strict_types=1);

namespace Tarjim;

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
        $problems = $this->config->refreshProblems();
        if ($problems !== []) {
            return new RefreshResult(RefreshResult::MISCONFIGURED, implode('; ', $problems));
        }

        throw new \LogicException('not implemented');
    }

    public function status(): Status
    {
        return new Status();
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
