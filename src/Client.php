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
        throw new \LogicException('not implemented');
    }

    /** @param array<string, mixed> $options */
    public function refresh(array $options = []): RefreshResult
    {
        throw new \LogicException('not implemented');
    }

    public function status(): Status
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     */
    public function t(string $key, array $args = [], array $options = []): string
    {
        throw new \LogicException('not implemented');
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
}
