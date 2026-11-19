<?php

declare(strict_types=1);

namespace Tarjim\Wire;

/** The answer to one delivery read. Which fields are set depends on $kind. */
final class Outcome
{
    // meta
    public const RECEIVED = 'received';
    public const NOT_MODIFIED = 'notModified';
    public const UNRELEASED = 'unreleased';
    public const CONFIGURATION = 'configuration';
    // manifest and files
    public const VERIFIED = 'verified';
    public const CHECKSUM_MISMATCH = 'checksumMismatch';
    public const HASH_MISMATCH = 'hashMismatch';
    public const SIZE_MISMATCH = 'sizeMismatch';
    public const REFUSED = 'refused';
    public const UNFETCHABLE = 'unfetchable';
    // any read
    public const UNREADABLE = 'unreadable';
    public const THROTTLED = 'throttled';
    public const SERVER = 'server';
    public const NETWORK = 'network';

    /** @var string */
    public $kind;

    /** @var int|null */
    public $status;

    /** @var string|null the problem's `code` on a configuration answer */
    public $code;

    /** @var int|null seconds, capped */
    public $retryAfter;

    /** @var Meta|null */
    public $meta;

    /** @var Manifest|null */
    public $manifest;

    /** @var string|null verified bytes of a manifest or a file */
    public $bytes;

    public function __construct(string $kind)
    {
        $this->kind = $kind;
    }
}
