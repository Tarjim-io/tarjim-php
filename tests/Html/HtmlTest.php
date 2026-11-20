<?php

declare(strict_types=1);

namespace Tarjim\Tests\Html;

use Tarjim\Client;
use Tarjim\Tests\Lookup\LookupCase;
use Tarjim\Tests\Support\ReleaseBuilder;

/** tHtml() returns HTML safe to place in a page body: only the listed tags and attributes survive. */
final class HtmlTest extends LookupCase
{
    /** @var callable|null */
    private $previousHandler;

    protected function setUp(): void
    {
        parent::setUp();
        // As in Laravel: any warning or notice becomes an exception.
        $this->previousHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    /** @param array<string, string> $keys */
    private function make(array $keys, array $overrides = []): Client
    {
        return $this->installed((new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', $keys), $overrides + ['locale' => 'en']);
    }

    private function html(string $value, array $args = [], array $overrides = []): string
    {
        return $this->make(['k' => $value], $overrides)->tHtml('k', $args);
    }

    /** @return iterable<string, array{string}> */
    public function keptAsIs(): iterable
    {
        yield 'plain text' => ['Hello world'];
        yield 'bold and italic' => ['<b>Bold</b> <strong>strong</strong> <i>i</i> <em>em</em> <u>u</u>'];
        yield 'a line break' => ['one<br>two'];
        yield 'paragraphs' => ['<p>one</p><p>two</p>'];
        yield 'lists' => ['<ul><li>a</li><li>b</li></ul><ol><li>c</li></ol>'];
        yield 'headings' => ['<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>'];
        yield 'a rule' => ['a<hr>b'];
        yield 'divs and spans' => ['<div><span>x</span></div>'];
        yield 'a table' => ['<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>'];
        yield 'an https link' => ['<a href="https://example.test/x?a=1&amp;b=2">link</a>'];
        yield 'a mail link' => ['<a href="mailto:hi@example.test">mail</a>'];
        yield 'a phone link' => ['<a href="tel:+15551234">call</a>'];
        yield 'an https image' => ['<img src="https://cdn.example.test/a.png" alt="A">'];
        yield 'arabic' => ['<p>مرحبا <b>بكم</b></p>'];
        yield 'an entity' => ['Tom &amp; Jerry &lt;3'];
    }

    /** @dataProvider keptAsIs */
    public function testSafeMarkupComesBackUnchanged(string $value): void
    {
        self::assertSame($value, $this->html($value));
    }

    /** @return iterable<string, array{string, string}> */
    public function stripped(): iterable
    {
        yield 'script with its content' => ['a<script>alert(1)</script>b', 'ab'];
        yield 'style with its content' => ['a<style>p{}</style>b', 'ab'];
        yield 'an iframe' => ['a<iframe src="https://evil.test"></iframe>b', 'ab'];
        yield 'a form control' => ['a<input value="x">b<button>go</button>', 'abgo'];
        yield 'a title' => ['<title>t</title>x', 'tx'];
        yield 'an unknown tag keeps its text' => ['<font color="red">red</font>', 'red'];
        yield 'event attributes' => ['<b onclick="alert(1)" onmouseover="x">b</b>', '<b>b</b>'];
        yield 'style, id, target off a link' => ['<p style="color:red" class="c" id="i" target="_blank">p</p>', '<p class="c">p</p>'];
        yield 'a javascript link' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'a javascript link in disguise' => ['<a href=" JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'];
        yield 'an entity-encoded javascript link' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'a data link' => ['<a href="data:text/html,x">x</a>', '<a>x</a>'];
        yield 'a plain http link' => ['<a href="http://example.test">x</a>', '<a>x</a>'];
        yield 'a relative link' => ['<a href="/path">x</a>', '<a>x</a>'];
        yield 'a link target' => ['<a href="https://example.test" target="_blank" rel="x">x</a>', '<a href="https://example.test" target="_blank" rel="noopener noreferrer">x</a>'];
        yield 'an http image' => ['<img src="http://cdn.example.test/a.png" alt="A">', '<img alt="A">'];
        yield 'an image with an event' => ['<img src="https://cdn.example.test/a.png" onerror="alert(1)">', '<img src="https://cdn.example.test/a.png">'];
        yield 'svg' => ['<svg><script>alert(1)</script></svg>ok', 'ok'];
        yield 'a comment' => ['a<!-- hidden -->b', 'ab'];
        yield 'cdata' => ['a<![CDATA[x]]>b', 'ab'];
        yield 'a base tag' => ['<base href="https://evil.test">x', 'x'];
        yield 'a meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">x', 'x'];
    }

    /** @dataProvider stripped */
    public function testAnythingElseIsRemoved(string $value, string $expected): void
    {
        self::assertSame($expected, $this->html($value));
    }

    public function testALoneLessThanSignKeepsTheRestOfTheText(): void
    {
        self::assertSame('Less than &lt;10 minutes, a &lt; b, x&lt;y', $this->html('Less than <10 minutes, a < b, x<y'));
    }

    public function testABareAmpersandIsEscaped(): void
    {
        self::assertSame('Tom &amp; Jerry, R&amp;D, &amp;nope', $this->html('Tom & Jerry, R&D, &nope'));
    }

    public function testUnclosedTagsDoNotSwallowTheText(): void
    {
        $out = $this->html('<b>bold and more');

        self::assertStringContainsString('bold and more', $out);
    }

    public function testInsertedValuesAreEscaped(): void
    {
        self::assertSame(
            '<b>Hi &lt;script&gt;alert(1)&lt;/script&gt;</b>',
            $this->html('<b>Hi {name}</b>', ['name' => '<script>alert(1)</script>'])
        );
    }

    public function testAnInsertedValueCannotAddATagOrAttribute(): void
    {
        $out = $this->html('<a href="https://example.test/{path}">x</a>', ['path' => '" onclick="alert(1)']);

        self::assertDoesNotMatchRegularExpression('/<a\\s[^>]*\\bonclick=/', $out);
        self::assertStringContainsString('>x</a>', $out);
    }

    public function testALegacyLinkPlaceholderKeepsASafeLink(): void
    {
        self::assertSame(
            '<a href="https://example.test/page">here</a>',
            $this->html('<a href="%%link%%">here</a>', ['link' => 'https://example.test/page'], ['legacyPercentPlaceholders' => true])
        );
    }

    public function testALegacyLinkPlaceholderCannotSmuggleAScript(): void
    {
        self::assertSame(
            '<a>here</a>',
            $this->html('<a href="%%link%%">here</a>', ['link' => 'javascript:alert(1)'], ['legacyPercentPlaceholders' => true])
        );
    }

    public function testTheKeyTextFallbackIsEscaped(): void
    {
        $client = $this->make(['k' => 'x']);

        self::assertSame('&lt;b&gt;missing&lt;/b&gt;', $client->tHtml('<b>missing</b>'));
    }

    public function testArabicRoundTripsByteForByte(): void
    {
        $value = "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627} \u{0628}\u{0643}\u{0645}";

        self::assertSame($value, $this->html($value));
    }

    public function testEmojiAndCombiningMarksSurvive(): void
    {
        $value = "Caf\u{0065}\u{0301} \u{1F600}";

        self::assertSame($value, $this->html($value));
    }

    public function testLibxmlErrorStateIsRestored(): void
    {
        $before = libxml_use_internal_errors(false);
        $this->html('<b>broken <i>nesting</b></i> <unknown-tag>');
        $after = libxml_use_internal_errors($before);

        self::assertFalse($after);
        self::assertSame([], libxml_get_errors());
    }

    public function testNeverThrowsOnHostileMarkup(): void
    {
        $values = [
            str_repeat('<div>', 5000) . 'deep',
            '<a href="' . str_repeat('x', 100000) . '">long</a>',
            "\0<b>nul</b>",
            "\xff\xfe invalid utf8 <b>x</b>",
            '<?xml version="1.0"?><b>x</b>',
            '<!DOCTYPE html><html><head><title>t</title></head><body><b>x</b></body></html>',
        ];
        $client = $this->make(array_combine(array_map('strval', array_keys($values)), $values));
        foreach (array_keys($values) as $key) {
            self::assertIsString($client->tHtml((string) $key));
        }
    }

    public function testPlainTextIsPassedThroughTForComparison(): void
    {
        $client = $this->make(['k' => '<b>Hi {name}</b>']);

        self::assertSame('<b>Hi <i></b>', $client->t('k', ['name' => '<i>']));
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public function argumentsAfterALessThanSign(): iterable
    {
        yield 'a link' => ['Orders under <{n} ship free.', ['n' => 'a href=https://evil.example/phish'], 'Orders under &lt;a href=https://evil.example/phish ship free.'];
        yield 'an image' => ['Orders under <{n} ship free.', ['n' => 'img src=https://evil.example/pixel.gif'], 'Orders under &lt;img src=https://evil.example/pixel.gif ship free.'];
        yield 'a tag name' => ['<{t}>hi</{t}>', ['t' => 'a href=https://evil.example'], '&lt;a href=https://evil.example&gt;hi&lt;/a href=https://evil.example&gt;'];
    }

    /**
     * @dataProvider argumentsAfterALessThanSign
     * @param array<string, string> $args
     */
    public function testAnArgumentCannotBecomeATag(string $value, array $args, string $expected): void
    {
        self::assertSame($expected, $this->html($value, $args));
    }

    public function testAnArgumentInAnUnquotedAttributeCannotAddAnother(): void
    {
        $out = $this->html('<img alt={x} src="https://cdn.example.test/a.png">', ['x' => 'y src=https://evil.example/track.gif']);

        self::assertSame(1, preg_match_all('/\ssrc="/', $out));
        self::assertStringContainsString(' src="https://cdn.example.test/a.png"', $out);
    }

    /** @return iterable<string, array{string, string}> */
    public function closingTagsWithAttributes(): iterable
    {
        yield 'a class' => ['<b>x</b class=y>z', '<b>x</b>z'];
        yield 'an href' => ['<a href="https://ok.example">x</a href=https://evil>z', '<a href="https://ok.example">x</a>z'];
        yield 'quoted with a greater-than' => ['<b>x</b onclick=1 title="a>b">z', '<b>x</b>z'];
        yield 'self-closing' => ['<b>x</b/>y', '<b>x</b>y'];
    }

    /** @dataProvider closingTagsWithAttributes */
    public function testAClosingTagWithAttributesIsTheSameOnEveryPhp(string $value, string $expected): void
    {
        self::assertSame($expected, $this->html($value));
    }

    /** @return iterable<string, array{string, string}> */
    public function textThatOnlyLooksLikeMarkup(): iterable
    {
        yield 'an unknown tag name' => ['x<y>z', 'x&lt;y&gt;z'];
        yield 'a key name' => ['press <Enter to go', 'press &lt;Enter to go'];
        yield 'a placeholder in angle brackets' => ['Dear <name>,', 'Dear &lt;name&gt;,'];
        yield 'an unclosed processing instruction' => ['Really <? no', 'Really &lt;? no'];
        yield 'an unclosed doctype' => ['see <!DOCTYPE', 'see &lt;!DOCTYPE'];
        yield 'an unclosed comment' => ['a <!-- not closed', 'a &lt;!-- not closed'];
        yield 'a non-breaking space entity' => ['a&nbsp;b', 'a&nbsp;b'];
    }

    /** @dataProvider textThatOnlyLooksLikeMarkup */
    public function testTextThatOnlyLooksLikeMarkupKeepsEveryCharacter(string $value, string $expected): void
    {
        self::assertSame($expected, $this->html($value));
    }

    public function testAHugeUnclosedCommentDoesNotEmptyTheOutput(): void
    {
        $out = $this->html('Keep this. <!-- ' . str_repeat('x', 1000000));

        self::assertStringStartsWith('Keep this.', $out);
    }

    /** @return iterable<string, array{string, string}> */
    public function classesAndTargets(): iterable
    {
        yield 'class on a tag' => ['<b class="hl">x</b>', '<b class="hl">x</b>'];
        yield 'class on a cell' => ['<table><tbody><tr><td class="a b">x</td></tr></tbody></table>', '<table><tbody><tr><td class="a b">x</td></tr></tbody></table>'];
        yield 'class on an image' => ['<img src="https://cdn.example.test/a.png" alt="A" class="logo">', '<img src="https://cdn.example.test/a.png" alt="A" class="logo">'];
        yield 'class with control characters' => ["<b class=\"a\x01b\">x</b>", '<b class="ab">x</b>'];
        yield 'class with quotes' => ['<b class=\'x"onclick=y\'>x</b>', '<b class="x&quot;onclick=y">x</b>'];
        yield 'link order' => ['<a target="_blank" class="btn" href="https://example.test">x</a>', '<a href="https://example.test" class="btn" target="_blank" rel="noopener noreferrer">x</a>'];
        yield 'target in capitals' => ['<a href="https://example.test" target="_BLANK">x</a>', '<a href="https://example.test" target="_blank" rel="noopener noreferrer">x</a>'];
        yield 'target self' => ['<a href="https://example.test" target="_self">x</a>', '<a href="https://example.test">x</a>'];
        yield 'target top' => ['<a href="https://example.test" target="_top">x</a>', '<a href="https://example.test">x</a>'];
        yield 'target named frame' => ['<a href="https://example.test" target="main">x</a>', '<a href="https://example.test">x</a>'];
        yield 'target on a dropped link' => ['<a href="javascript:alert(1)" target="_blank">x</a>', '<a>x</a>'];
        yield 'target off a link' => ['<span target="_blank">x</span>', '<span>x</span>'];
        yield 'rel without a target' => ['<a href="https://example.test" rel="nofollow">x</a>', '<a href="https://example.test">x</a>'];
        yield 'style still dropped' => ['<span style="color:red" class="c">x</span>', '<span class="c">x</span>'];
        yield 'a mail link opening a tab' => ['<a href="mailto:hi@example.test" target="_blank">m</a>', '<a href="mailto:hi@example.test" target="_blank" rel="noopener noreferrer">m</a>'];
    }

    /** @dataProvider classesAndTargets */
    public function testKeepsClassesAndNewTabLinks(string $value, string $expected): void
    {
        self::assertSame($expected, $this->html($value));
    }

    public function testAnArgumentInAClassStaysInTheClass(): void
    {
        $out = $this->html('<b class="{c}">x</b>', ['c' => 'y" onclick="alert(1)']);

        self::assertMatchesRegularExpression('/^<b class="[^"]*">x<\/b>$/', $out, 'one attribute, the class');
    }
}

