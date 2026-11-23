<?php

declare(strict_types=1);

namespace Tarjim\Export;

final class ExportResult
{
    public const UPDATED = 'updated';
    public const FAILED = 'failed';
    public const MISCONFIGURED = 'misconfigured';

    /** @var string one of the constants above */
    public $status;

    /** @var int|null the export this pull installed or tried */
    public $exportId;

    /** @var list<string> paths relative to the target folder */
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
