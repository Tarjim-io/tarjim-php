<?php

declare(strict_types=1);

namespace Tarjim\Html;

use Tarjim\Lookup\Placeholders;

/**
 * Turns stored text into HTML that is safe in a page body: only the listed tags and attributes
 * survive. Stray markup in the stored text is escaped, arguments are escaped and substituted, then
 * the whole result is sanitised, so the scheme check sees the final `href` and `src`.
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

    /**
     * libxml stops parsing at 255 levels and silently drops the rest of the input, so text nested
     * deeper than that never reaches us. This bound only keeps the walk below it cheap.
     */
    private const MAX_DEPTH = 200;

    /**
     * Element names a browser treats as a tag. A `<` followed by anything else ("<y>", "<Enter",
     * "<name>") is plain text and is escaped, so it keeps every character.
     */
    private const HTML_ELEMENTS = [
        'html', 'head', 'body', 'title', 'base', 'link', 'meta', 'style', 'script', 'noscript', 'template',
        'iframe', 'object', 'embed', 'param', 'video', 'audio', 'source', 'track', 'canvas', 'map', 'area', 'svg',
        'math', 'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'form',
        'fieldset', 'legend', 'label', 'input', 'button', 'select', 'datalist', 'optgroup', 'option', 'textarea',
        'output', 'progress', 'meter', 'details', 'summary', 'dialog', 'menu', 'a', 'abbr', 'address', 'article',
        'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'cite', 'code', 'data', 'dd', 'del', 'dfn', 'div', 'dl',
        'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'i', 'img', 'ins', 'kbd', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'picture', 'pre', 'q', 'rp', 'rt',
        'ruby', 's', 'samp', 'section', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'ul', 'var', 'wbr',
        'font', 'center', 'big', 'strike', 'tt', 'marquee', 'blink', 'frame', 'frameset', 'noframes', 'xmp',
        'plaintext', 'listing', 'nobr', 'acronym', 'applet', 'basefont', 'dir', 'isindex', 'keygen', 'menuitem',
        'noembed', 'image',
    ];

    /** @var array<string, true>|null */
    private static $tagNames;

    /** @param array<array-key, mixed> $args */
    public static function render(string $text, array $args, bool $legacy): string
    {
        if (!extension_loaded('dom')) {
            return htmlspecialchars(Placeholders::fill($text, $args, $legacy), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Stray markup is escaped in the stored text, before any argument is inserted: an argument
        // after a `<` must stay text, and nothing it contains may be read as markup.
        $prepared = self::escapeStrayMarkup($text);
        if ($prepared !== null) {
            $html = self::cleanBytes(Placeholders::fill($prepared, $args, $legacy, [self::class, 'escapeArgument']));
            if ($html !== null) {
                return self::sanitise($html);
            }
        }

        return htmlspecialchars(Placeholders::fill($text, $args, $legacy), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Whitespace, `=` and the backtick are written as character references too: an argument placed
     * in an unquoted attribute value would otherwise end the value and start another attribute.
     */
    public static function escapeArgument(string $value): string
    {
        return strtr(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), [
            ' ' => '&#32;', "\t" => '&#9;', "\n" => '&#10;', "\r" => '&#13;', "\f" => '&#12;',
            '=' => '&#61;', '`' => '&#96;',
        ]);
    }

    private static function sanitise(string $html): string
    {
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

    /**
     * NUL and other control characters, and bytes that are not UTF-8, would make libxml refuse or
     * mangle the text. Null when PCRE gives up.
     */
    private static function cleanBytes(string $text): ?string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        if ($text === null) {
            return null;
        }
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return preg_replace_callback(
            '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
            static function (array $m): string {
                return $m[1] ?? "\u{FFFD}";
            },
            $text
        );
    }

    /**
     * libxml cuts the text at a `<` that cannot start a tag ("under <10 minutes" would lose its
     * tail) and drops a bare `&`, so both are escaped before parsing. A `<` is left alone only when
     * it starts a real element name; an end tag is reduced to its bare form because libxml versions
     * disagree about attributes on one.
     *
     * Comments, CDATA sections, processing instructions and doctypes are cut out with their content,
     * because libxml versions disagree on them too. One that never ends is plain text.
     *
     * Null when PCRE gives up (a hostile input can exhaust its backtrack limit).
     */
    private static function escapeStrayMarkup(string $html): ?string
    {
        $html = self::withoutConstructs($html);
        $html = preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]*+|#[0-9]++|#[xX][0-9A-Fa-f]++);)/', '&amp;', $html);
        if ($html === null) {
            return null;
        }
        $html = preg_replace_callback(
            '/<(\/?+)(?:([A-Za-z][A-Za-z0-9-]*+)(?=[\s>\/]))?/',
            static function (array $m): string {
                if (isset($m[2]) && isset(self::tagNames()[strtolower($m[2])])) {
                    return $m[0];
                }

                return '&lt;' . substr($m[0], 1);
            },
            $html
        );

        return $html === null ? null : self::bareEndTags($html);
    }

    /**
     * Scanned with strpos rather than a lazy regex: a megabyte comment with no end would exhaust
     * the PCRE backtrack limit. A terminator that is missing once is missing for every later start
     * too, so the scan stays linear however many unclosed starts there are.
     */
    private static function withoutConstructs(string $html): string
    {
        $starts = [['<!--', '-->'], ['<![CDATA[', ']]>'], ['<?', '>'], ['<!DOCTYPE', '>']];
        $unterminated = [];
        $out = '';
        $pos = 0;
        $at = 0;
        while (($at = strpos($html, '<', $at)) !== false) {
            foreach ($starts as [$open, $close]) {
                if (substr_compare($html, $open, $at, strlen($open), true) !== 0 || isset($unterminated[$close])) {
                    continue;
                }
                $end = strpos($html, $close, $at + strlen($open));
                if ($end === false) {
                    $unterminated[$close] = true;
                    continue;
                }
                $out .= substr($html, $pos, $at - $pos);
                $pos = $at = $end + strlen($close);
                continue 2;
            }
            $at++;
        }

        return $out . substr($html, $pos);
    }

    /**
     * `</b class=y>`, `</b/>` and a quoted value holding `>` all become `</b>`. By now every `</`
     * is followed by a real element name. A tag that never ends, or a quote that never closes, stops
     * the scan and leaves the rest as it was.
     */
    private static function bareEndTags(string $html): string
    {
        $length = strlen($html);
        $out = '';
        $pos = 0;
        while (($at = strpos($html, '</', $pos)) !== false) {
            $nameEnd = $at + 2 + strcspn($html, " \t\n\r\f/>", $at + 2);
            $i = $nameEnd;
            while (true) {
                $i += strcspn($html, '>"\'', $i);
                if ($i >= $length) {
                    return $out . substr($html, $pos);
                }
                if ($html[$i] === '>') {
                    break;
                }
                $quoteEnd = strpos($html, $html[$i], $i + 1);
                if ($quoteEnd === false) {
                    return $out . substr($html, $pos);
                }
                $i = $quoteEnd + 1;
            }
            $out .= substr($html, $pos, $nameEnd - $pos) . '>';
            $pos = $i + 1;
        }

        return $out . substr($html, $pos);
    }

    /** @return array<string, true> */
    private static function tagNames(): array
    {
        if (self::$tagNames === null) {
            self::$tagNames = array_fill_keys(
                array_merge(self::KEPT, self::DROPPED_WITH_CONTENT, self::HTML_ELEMENTS),
                true
            );
        }

        return self::$tagNames;
    }

    private static function children(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText && !$child instanceof \DOMCdataSection) {
                $out .= self::text($child->data);
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
            return self::text($element->textContent);
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

    /** U+00A0 goes back out as `&nbsp;` so text that arrived with the entity is byte-identical. */
    private static function text(string $value): string
    {
        return str_replace("\u{00A0}", '&nbsp;', htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'));
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
        return ' ' . $name . '="' . str_replace("\u{00A0}", '&nbsp;', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '"';
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
