<?php

declare(strict_types=1);

namespace Tarjim\Export;

final class ExportResult
{
    /** @var string */
    public $status;

    /** @var list<string> */
    public $written = [];

    /** @var list<string> */
    public $removed = [];

    /** @var string */
    public $message = '';

    public function __construct(string $status)
    {
        $this->status = $status;
    }
}
