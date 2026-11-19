<?php

declare(strict_types=1);

namespace Tarjim\Export;

/** A pull that must stop. The message is written for the caller and never carries the key or a response body. */
final class Refusal extends \RuntimeException
{
    /** @var string */
    public $status;

    public function __construct(string $message, string $status = ExportResult::FAILED)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
