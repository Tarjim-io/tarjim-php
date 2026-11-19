<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class Meta
{
    /** @var string 64 lowercase hex: sha256 of the manifest */
    public $checksum;

    /** @var int */
    public $schemaVersion;

    /** @var string */
    public $manifestUrl;

    /** @var string */
    public $slicesBaseUrl;

    /** @var bool true: origin mode, URLs relative to the delivery URL, key headers sent */
    public $authenticated;

    /** @var string|null CDN mode only, appended verbatim */
    public $signedQuery;

    /** @var string digits only */
    public $releaseId;

    /** @var string|null */
    public $distribution;

    /** @var string|null */
    public $stage;

    /** @var string|null the response's ETag, sent back as If-None-Match */
    public $etag;

    public function __construct(
        string $checksum,
        int $schemaVersion,
        string $manifestUrl,
        string $slicesBaseUrl,
        bool $authenticated,
        ?string $signedQuery,
        string $releaseId,
        ?string $distribution = null,
        ?string $stage = null,
        ?string $etag = null
    ) {
        $this->checksum = $checksum;
        $this->schemaVersion = $schemaVersion;
        $this->manifestUrl = $manifestUrl;
        $this->slicesBaseUrl = $slicesBaseUrl;
        $this->authenticated = $authenticated;
        $this->signedQuery = $signedQuery;
        $this->releaseId = $releaseId;
        $this->distribution = $distribution;
        $this->stage = $stage;
        $this->etag = $etag;
    }
}
