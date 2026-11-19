<?php

declare(strict_types=1);

namespace Tarjim\Wire;

/**
 * Turns the address a meta names into a URL that is safe to request, or null.
 *
 * Origin mode sends the key, so the address is resolved against the delivery URL and must land on
 * the same origin. CDN mode sends no key and takes an absolute https address plus the signed query.
 */
final class ObjectUrl
{
    private const QUERY = '/^[A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%]+\z/';

    public static function forMeta(string $deliveryUrl, Meta $meta, string $reference): ?string
    {
        if ($reference === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $reference) === 1) {
            return null;
        }

        return $meta->authenticated ? self::sameOrigin($deliveryUrl, $reference) : self::signed($meta, $reference);
    }

    private static function signed(Meta $meta, string $reference): ?string
    {
        $query = $meta->signedQuery;
        if (strpos($reference, 'https://') !== 0 || strpos($reference, '?') !== false || strpos($reference, '#') !== false) {
            return null;
        }
        if ($query === null || preg_match(self::QUERY, $query) !== 1) {
            return null;
        }
        $host = parse_url($reference, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return $reference . '?' . $query;
    }

    private static function sameOrigin(string $deliveryUrl, string $reference): ?string
    {
        // No legitimate address carries credentials, and '@' is how a reference turns the base host
        // into a userinfo prefix of another one.
        if (strpos($reference, '@') !== false) {
            return null;
        }
        $base = self::split($deliveryUrl);
        $ref = self::split($reference);
        if ($base === null || $ref === null || $base['scheme'] === null || $base['authority'] === null) {
            return null;
        }
        // RFC 3986 forbids a colon in the first segment of a relative path (it would read as a
        // scheme); ":8443/x" is a port written as a path, so it is refused rather than guessed at.
        if ($ref['scheme'] === null && $ref['authority'] === null && strpos(explode('/', $ref['path'], 2)[0], ':') !== false) {
            return null;
        }

        if ($ref['scheme'] !== null) {
            $scheme = $ref['scheme'];
            $authority = $ref['authority'];
            $path = self::removeDots($ref['path']);
            $query = $ref['query'];
        } elseif ($ref['authority'] !== null) {
            $scheme = $base['scheme'];
            $authority = $ref['authority'];
            $path = self::removeDots($ref['path']);
            $query = $ref['query'];
        } else {
            $scheme = $base['scheme'];
            $authority = $base['authority'];
            $query = $ref['query'];
            if ($ref['path'] === '') {
                $path = $base['path'];
                $query = $query ?? $base['query'];
            } elseif ($ref['path'][0] === '/') {
                $path = self::removeDots($ref['path']);
            } else {
                // A base with an authority and an empty path merges as "/" + reference.
                $slash = strrpos($base['path'], '/');
                $directory = $slash === false ? '/' : substr($base['path'], 0, $slash + 1);
                $path = self::removeDots($directory . $ref['path']);
            }
        }

        if ($authority === null) {
            return null;
        }
        $want = self::origin($base['scheme'], $base['authority']);
        if ($want === null || $want !== self::origin($scheme, $authority)) {
            return null;
        }

        $built = strtolower($scheme) . '://' . $authority . $path . ($query === null ? '' : '?' . $query);

        // What gets requested is the built string, so it is parsed again rather than trusting the parts.
        return self::originOfUrl($built) === self::originOfUrl($deliveryUrl) && self::originOfUrl($built) !== null ? $built : null;
    }

    private static function originOfUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /** @return array{scheme: string|null, authority: string|null, path: string, query: string|null}|null */
    private static function split(string $url): ?array
    {
        $pattern = '~^(?:([A-Za-z][A-Za-z0-9+.\-]*):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#.*)?\z~s';
        if (preg_match($pattern, $url, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return ['scheme' => $m[1], 'authority' => $m[2], 'path' => (string) $m[3], 'query' => $m[4] ?? null];
    }

    /** scheme://host:port with the port made explicit, or null for anything but http(s) with a plain host. */
    private static function origin(string $scheme, string $authority): ?string
    {
        $scheme = strtolower($scheme);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        // Credentials are never accepted, even on the right host.
        if (preg_match('~^(\[[0-9A-Fa-f:.]+\]|[^:\[\]@]+)(?::([0-9]*))?\z~', $authority, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($m[1]) . ':' . $port;
    }

    private static function removeDots(string $path): string
    {
        $absolute = $path !== '' && $path[0] === '/';
        $segments = explode('/', $absolute ? substr($path, 1) : $path);
        $last = count($segments) - 1;
        $out = [];
        foreach ($segments as $i => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..') {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $out);
    }
}
