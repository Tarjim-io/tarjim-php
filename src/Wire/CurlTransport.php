<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class CurlTransport implements Transport
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $stallTimeout;

    /** @var string|null */
    private $caBundle;

    public function __construct(int $connectTimeout = 5, int $stallTimeout = 20, ?string $caBundle = null)
    {
        $this->connectTimeout = $connectTimeout;
        $this->stallTimeout = $stallTimeout;
        $this->caBundle = $caBundle;
    }

    public function send(Request $request): Response
    {
        throw new \LogicException('not implemented');
    }
}
