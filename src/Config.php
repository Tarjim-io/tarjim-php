<?php

declare(strict_types=1);

namespace Tarjim;

final class Config
{
    /** @var array<string, mixed> */
    private $values;

    /** @var list<string> */
    private $problems;

    /**
     * @param array<string, mixed> $values
     * @param list<string> $problems
     */
    private function __construct(array $values, array $problems)
    {
        $this->values = $values;
        $this->problems = $problems;
    }

    /**
     * Never throws; whatever is wrong is listed by problems().
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        throw new \LogicException('not implemented');
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return mixed */
    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }
}
