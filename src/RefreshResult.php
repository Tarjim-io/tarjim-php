<?php

declare(strict_types=1);

namespace Tarjim;

final class RefreshResult
{
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const NOT_DUE = 'notDue';
    public const BUSY = 'busy';
    public const UNRELEASED = 'unreleased';
    public const FAILED = 'failed';
    public const MISCONFIGURED = 'misconfigured';

    /** @var string one of the constants above */
    public $status;

    /** @var string */
    public $message;

    /** @var int|null seconds until a check may run again */
    public $retryAfter;

    /** @var bool a complete copy is held after this refresh, whatever its outcome */
    public $copyHeld;

    public function __construct(string $status, string $message = '', ?int $retryAfter = null, bool $copyHeld = false)
    {
        $this->status = $status;
        $this->message = $message;
        $this->retryAfter = $retryAfter;
        $this->copyHeld = $copyHeld;
    }
}
