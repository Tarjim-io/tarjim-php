<?php

declare(strict_types=1);

namespace Tarjim;

final class Image
{
    /** @var string */
    public $url;

    /** @var array<string, string> */
    public $attributes;

    /** @param array<string, string> $attributes */
    public function __construct(string $url, array $attributes = [])
    {
        $this->url = $url;
        $this->attributes = $attributes;
    }
}
