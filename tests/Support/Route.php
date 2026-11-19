<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

/** What the local test server answers on one path. */
final class Route
{
    /** @var array<string, mixed> */
    public $spec;

    /** @param array<string, string> $headers */
    private function __construct(int $status, string $body, array $headers)
    {
        $this->spec = [
            'status' => $status,
            'headers' => $headers,
            'body' => base64_encode($body),
            'gzip' => false,
            'repeat' => 1,
            'stallAfter' => null,
            'stallSeconds' => 0,
        ];
    }

    /** @param array<string, string> $headers names sent exactly as given, case included */
    public static function answer(int $status, string $body = '', array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** @param mixed $value */
    public static function json(int $status, $value, array $headers = []): self
    {
        return new self($status, (string) json_encode($value), $headers + ['Content-Type' => 'application/json']);
    }

    public static function redirect(int $status, string $location): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /** Sent with Content-Encoding: gzip; the verified bytes are the decoded ones. */
    public function gzip(): self
    {
        $this->spec['gzip'] = true;

        return $this;
    }

    /** The body is sent this many times over, streamed in pieces. */
    public function repeat(int $times): self
    {
        $this->spec['repeat'] = $times;

        return $this;
    }

    /** Send this many body bytes, then go silent for $seconds before finishing. */
    public function stall(int $afterBytes, int $seconds): self
    {
        $this->spec['stallAfter'] = $afterBytes;
        $this->spec['stallSeconds'] = $seconds;

        return $this;
    }
}
