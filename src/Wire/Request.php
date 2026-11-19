<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class Request
{
    /** @var string */
    public $url;

    /** @var array<string, string> */
    public $headers;

    /** @var int largest decoded body accepted, in bytes */
    public $limit;

    /** @param array<string, string> $headers */
    public function __construct(string $url, array $headers, int $limit)
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->limit = $limit;
    }
}
