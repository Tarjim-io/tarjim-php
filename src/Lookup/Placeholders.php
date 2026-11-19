<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

/**
 * Fills `{name}` (and, on request, `%%name%%`) from named arguments. Mirrors the JavaScript core's
 * scan so both clients give the same text.
 */
final class Placeholders
{
    /**
     * One left-to-right scan: inserted text is never scanned again, and a name with no usable
     * argument stays as written.
     *
     * @param array<array-key, mixed> $args
     * @param (callable(string): string)|null $escape applied to each inserted value
     */
    public static function fill(string $text, array $args, bool $legacy, ?callable $escape = null): string
    {
        $named = [];
        foreach ($args as $name => $value) {
            // Positional entries never fill a placeholder, and PHP turns "5" into an int key.
            if (is_string($name)) {
                $named[$name] = $value;
            }
        }
        if ($named === []) {
            return $text;
        }

        $stops = $legacy ? '{%' : '{';
        $length = strlen($text);
        $out = '';
        $copied = 0;
        $i = 0;
        while ($i < $length) {
            $i += strcspn($text, $stops, $i);
            if ($i >= $length) {
                break;
            }
            $pattern = $text[$i] === '{' ? '/\G\{([^{}]+)\}/' : '/\G%%([^%\s]+)%%/';
            if (preg_match($pattern, $text, $match, 0, $i) !== 1 || !array_key_exists($match[1], $named)) {
                $i++;
                continue;
            }
            $size = strlen($match[0]);
            $inserted = self::stringify($named[$match[1]]);
            if ($inserted === null) {
                $i += $size;
                continue;
            }
            $out .= substr($text, $copied, $i - $copied) . ($escape === null ? $inserted : $escape($inserted));
            $i += $size;
            $copied = $i;
        }

        return $out . substr($text, $copied);
    }

    /** @param mixed $value */
    private static function stringify($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            try {
                return (string) $value;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
