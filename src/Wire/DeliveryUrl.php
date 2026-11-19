<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class DeliveryUrl
{
    /** Why the URL cannot be used, or null when it can. The message never repeats the URL. */
    public static function problem(string $url): ?string
    {
        throw new \LogicException('not implemented');
    }
}
