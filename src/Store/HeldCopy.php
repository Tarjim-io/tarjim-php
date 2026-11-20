<?php

declare(strict_types=1);

namespace Tarjim\Store;

/** What current.json says is installed, after the files it names have been found readable. */
final class HeldCopy
{
    /** @var string digits */
    public $releaseId;

    /** @var string 64 lowercase hex: the manifest's sha256 */
    public $checksum;

    /** @var string|null */
    public $distribution;

    /** @var string|null */
    public $stage;

    /** @var int|null Unix time */
    public $installedAt;

    /** @var string file name of the release index in releases/ */
    public $index;

    /** @var string|null file name of the release installed before this one */
    public $previous;

    public function __construct(
        string $releaseId,
        string $checksum,
        ?string $distribution,
        ?string $stage,
        ?int $installedAt,
        string $index,
        ?string $previous
    ) {
        $this->releaseId = $releaseId;
        $this->checksum = $checksum;
        $this->distribution = $distribution;
        $this->stage = $stage;
        $this->installedAt = $installedAt;
        $this->index = $index;
        $this->previous = $previous;
    }
}
