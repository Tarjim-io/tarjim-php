<?php

declare(strict_types=1);

namespace Tarjim\Store;

/** The refresh bookkeeping in state.json. Anything unreadable in it counts as unset. */
final class State
{
    /** @var int|null Unix time of the last meta read, whatever it answered */
    public $lastCheckAt;

    /** @var int|null Unix time of the last read that was an answer, not a failure */
    public $lastSuccessAt;

    /** @var int|null no meta read before this Unix time */
    public $notBefore;

    /** @var string|null */
    public $etag;

    /** @var string|null "<release id>-<checksum>" the etag was issued for */
    public $etagFor;

    /** @param mixed $decoded */
    public static function fromDecoded($decoded): self
    {
        $state = new self();
        if (!is_array($decoded)) {
            return $state;
        }
        $state->lastCheckAt = self::time($decoded['lastCheckAt'] ?? null);
        $state->lastSuccessAt = self::time($decoded['lastSuccessAt'] ?? null);
        $state->notBefore = self::time($decoded['notBefore'] ?? null);
        $etag = $decoded['etag'] ?? null;
        $etagFor = $decoded['etagFor'] ?? null;
        $state->etag = is_string($etag) ? $etag : null;
        $state->etagFor = is_string($etagFor) ? $etagFor : null;

        return $state;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'lastCheckAt' => $this->lastCheckAt,
            'lastSuccessAt' => $this->lastSuccessAt,
            'notBefore' => $this->notBefore,
            'etag' => $this->etag,
            'etagFor' => $this->etagFor,
        ];
    }

    /** @param mixed $value */
    private static function time($value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
