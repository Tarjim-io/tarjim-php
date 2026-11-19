<?php

declare(strict_types=1);

namespace Tarjim;

final class Status
{
    /** @var string|null */
    public $releaseId;

    /** @var string|null */
    public $distribution;

    /** @var string|null */
    public $stage;

    /** @var string|null */
    public $source;

    /** @var int|null Unix time */
    public $installedAt;

    /** @var int|null Unix time */
    public $lastCheckAt;

    /** @var int|null Unix time */
    public $lastSuccessAt;

    /** @var bool */
    public $stale = false;
}
