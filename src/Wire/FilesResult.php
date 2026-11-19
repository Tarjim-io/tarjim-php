<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class FilesResult
{
    /** @var array<string, Outcome> by file hash */
    public $results;

    /** @var Meta the meta later requests must use: the re-read one when there was one */
    public $meta;

    /** @var bool */
    public $reread;

    /** @param array<string, Outcome> $results */
    public function __construct(array $results, Meta $meta, bool $reread)
    {
        $this->results = $results;
        $this->meta = $meta;
        $this->reread = $reread;
    }
}
