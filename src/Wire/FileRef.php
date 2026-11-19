<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class FileRef
{
    /** @var string 64 lowercase hex */
    public $hash;

    /** @var int bytes */
    public $size;

    public function __construct(string $hash, int $size)
    {
        $this->hash = $hash;
        $this->size = $size;
    }
}
