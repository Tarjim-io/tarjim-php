<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class DeliveryUrl
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Why the URL cannot be used, or null when it can. The message never repeats the URL.
     * $name is the option the problem is reported under: the API URL follows the same rules.
     *
     * The URL is used exactly as given and its path is never parsed, so it must be clean: the
     * query string the client appends has to be the only one.
     */
    public static function problem(string $url, string $name = 'deliveryUrl'): ?string
    {
        if ($url === '') {
            return $name . ' is required';
        }
        // Checked before parsing: a CR/LF or space that parse_url tolerates would reach the request line.
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return $name . ' must not contain whitespace or control characters';
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return $name . ' is not a URL';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, self::LOOPBACK, true))) {
            return $name . ' must be https';
        }
        if (isset($parts['user']) || isset($parts['pass']) || strpos($url, '?') !== false || strpos($url, '#') !== false) {
            return $name . ' must not have credentials, a query or a fragment';
        }

        return substr($url, -1) === '/' ? $name . ' must not end with a slash' : null;
    }
}
