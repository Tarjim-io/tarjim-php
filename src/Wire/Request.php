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

    /** @var string GET or POST */
    public $method;

    /** @var string|null request body, POST only */
    public $body;

    /** @var int|null seconds without progress before giving up; null uses the transport's own */
    public $stallTimeout;

    /** @param array<string, string> $headers */
    public function __construct(string $url, array $headers, int $limit, string $method = 'GET', ?string $body = null, ?int $stallTimeout = null)
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->limit = $limit;
        $this->method = $method;
        $this->body = $body;
        $this->stallTimeout = $stallTimeout;
    }
}
