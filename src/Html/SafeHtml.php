<?php

declare(strict_types=1);

namespace Tarjim\Html;

use Tarjim\Lookup\Placeholders;

/**
 * Turns stored text into HTML that is safe in a page body: only the listed tags and attributes
 * survive. Arguments are escaped, then substituted, then the whole result is sanitised, so the
 * scheme check sees the final `href` and `src`.
 */
final class SafeHtml
{
    private const KEPT = [
        'a', 'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'span', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img',
    ];

    private const VOID = ['br', 'hr', 'img'];

    /** Dropped with everything inside; any other unlisted element only loses its own tags. */
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'textarea', 'select',
        'option', 'head', 'base', 'meta', 'link',
    ];

    /** libxml stops at its own nesting limit; deeper than this is flattened to text. */
    private const MAX_DEPTH = 200;

    /** @param array<array-key, mixed> $args */
    public static function render(string $text, array $args, bool $legacy): string
    {
        $filled = Placeholders::fill($text, $args, $legacy, static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });
        if (!extension_loaded('dom')) {
            return htmlspecialchars($filled, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return self::sanitise($filled);
    }

    private static function sanitise(string $html): string
    {
        $html = self::escapeStrayMarkup(self::cleanBytes($html));
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // The meta tag is what makes libxml read the bytes as UTF-8; without it every non-ASCII
            // character is misread as Latin-1.
            $loaded = $doc->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
                . $html . '</body></html>',
                LIBXML_NONET
            );
            $body = $loaded ? $doc->getElementsByTagName('body')->item(0) : null;
            if ($body === null) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return self::children($body, 0);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** NUL and other control characters, and bytes that are not UTF-8, would make libxml refuse or mangle the text. */
    private static function cleanBytes(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
            static function (array $m): string {
                return $m[1] ?? "\u{FFFD}";
            },
            $text
        );
    }

    /**
     * libxml cuts the text at a `<` that cannot start a tag ("under <10 minutes" would lose its
     * tail) and drops a bare `&`, so both are escaped before parsing.
     *
     * Comments, CDATA sections, processing instructions and doctypes are cut out here with their
     * content, because libxml versions disagree on them: some drop a CDATA section, others keep it
     * as text.
     */
    private static function escapeStrayMarkup(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?(?:-->|$)|<!\[CDATA\[.*?(?:\]\]>|$)|<\?.*?(?:>|$)|<!DOCTYPE[^>]*(?:>|$)/is', '', $html);
        $html = (string) preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);)/', '&amp;', $html);

        return (string) preg_replace('/<(?!\/?[A-Za-z][A-Za-z0-9-]*[\s>\/])/', '&lt;', $html);
    }

    private static function children(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText && !$child instanceof \DOMCdataSection) {
                $out .= htmlspecialchars($child->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof \DOMElement) {
                $out .= self::element($child, $depth);
            }
        }

        return $out;
    }

    private static function element(\DOMElement $element, int $depth): string
    {
        $name = strtolower($element->nodeName);
        if (in_array($name, self::DROPPED_WITH_CONTENT, true)) {
            return '';
        }
        if ($depth >= self::MAX_DEPTH) {
            return htmlspecialchars($element->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $inner = self::children($element, $depth + 1);
        if (!in_array($name, self::KEPT, true)) {
            return $inner;
        }
        $open = '<' . $name . self::attributes($element, $name) . '>';
        if (in_array($name, self::VOID, true)) {
            return $open;
        }

        return $open . $inner . '</' . $name . '>';
    }

    private static function attributes(\DOMElement $element, string $name): string
    {
        $out = '';
        if ($name === 'a') {
            $href = self::url($element->getAttribute('href'), true);
            if ($href !== null) {
                $out .= self::attribute('href', $href);
            }
        } elseif ($name === 'img') {
            $src = self::url($element->getAttribute('src'), false);
            if ($src !== null) {
                $out .= self::attribute('src', $src);
            }
            if ($element->hasAttribute('alt')) {
                $out .= self::attribute('alt', $element->getAttribute('alt'));
            }
        }

        return $out;
    }

    private static function attribute(string $name, string $value): string
    {
        return ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }

    /**
     * The URL to keep, or null. The value is already entity-decoded by the parser. Whitespace,
     * quotes and angle brackets inside a URL are never valid and are what attacks lean on, so such
     * a URL is dropped rather than repaired.
     */
    private static function url(string $value, bool $allowContactSchemes): ?string
    {
        $value = (string) preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $value);
        if ($value === '' || preg_match('/[\x00-\x20"\'<>\\\\`\x7F]/', $value) === 1) {
            return null;
        }
        if (preg_match('~^https://(?:[^/?#@]*@)?[^/?#@]+~i', $value) === 1) {
            return $value;
        }
        if ($allowContactSchemes && preg_match('/^(?:mailto|tel):/i', $value) === 1) {
            return $value;
        }

        return null;
    }
}
