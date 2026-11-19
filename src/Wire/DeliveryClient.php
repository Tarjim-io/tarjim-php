<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class DeliveryClient
{
    /** @var Transport */
    private $transport;

    /** @var string */
    private $deliveryUrl;

    /** @var string */
    private $key;

    public function __construct(Transport $transport, string $deliveryUrl, string $key)
    {
        $this->transport = $transport;
        $this->deliveryUrl = $deliveryUrl;
        $this->key = $key;
    }

    public function fetchMeta(?string $heldReleaseId = null, ?string $heldEtag = null): Outcome
    {
        throw new \LogicException('not implemented');
    }

    public function fetchManifest(Meta $meta): Outcome
    {
        throw new \LogicException('not implemented');
    }

    /** @param list<FileRef> $files */
    public function fetchFiles(Meta $meta, array $files): FilesResult
    {
        throw new \LogicException('not implemented');
    }
}
