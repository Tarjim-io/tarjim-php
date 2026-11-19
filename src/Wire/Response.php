<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class Response
{
    /** @var int 0 when there is no usable answer: network failure, timeout, refused redirect */
    public $status;

    /** @var array<string, string> names lowercased */
    public $headers;

    /** @var string|null decoded bytes; null when the body was not read or was cut off */
    public $body;

    /** @var bool the decoded body passed the request's limit and was abandoned */
    public $tooLarge;

    /** @param array<string, string> $headers */
    public function __construct(int $status, array $headers = [], ?string $body = null, bool $tooLarge = false)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->tooLarge = $tooLarge;
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
