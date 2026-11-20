<?php

declare(strict_types=1);

namespace Tarjim\Tests\Docs;

use PHPUnit\Framework\TestCase;

/** The PHP shown in the documents parses, and names only methods and options the package has. */
final class ReadmeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public function documents(): iterable
    {
        yield 'README' => ['README.md'];
        yield 'migration guide' => ['docs/moving-from-tarjim-php-client.md'];
    }

    /** @return list<string> */
    private static function phpBlocks(string $document): array
    {
        preg_match_all('/^( *)```php\n(.*?)^ *```/sm', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document), $m, PREG_SET_ORDER);

        // A block inside a list item is indented; strip that indent before parsing it.
        return array_map(static function (array $match): string {
            return (string) preg_replace('/^' . $match[1] . '/m', '', $match[2]);
        }, $m);
    }

    /** @dataProvider documents */
    public function testEveryPhpExampleParses(string $document): void
    {
        $blocks = self::phpBlocks($document);
        self::assertNotEmpty($blocks, $document . ' has PHP examples');
        foreach ($blocks as $i => $block) {
            $file = tempnam(sys_get_temp_dir(), 'tarjim-doc-');
            file_put_contents((string) $file, strpos($block, '<?php') === 0 ? $block : "<?php\n" . $block);
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $file) . ' 2>&1', $output, $code);
            unlink((string) $file);
            self::assertSame(0, $code, $document . ' block ' . $i . ": " . implode("\n", $output));
        }
    }

    /** @dataProvider documents */
    public function testEveryClientMethodNamedExists(string $document): void
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
        preg_match_all('/->(\w+)\(/', $text, $m);
        // Framework methods the examples call around the package.
        $framework = ['everyFiveMinutes', 'singleton', 'getLocale'];
        $known = array_merge(get_class_methods(\Tarjim\Client::class), get_class_methods(\Tarjim\Export\Exports::class), $framework);
        foreach (array_unique($m[1]) as $method) {
            self::assertContains($method, $known, $document . ' names ->' . $method . '()');
        }
    }

    /** @dataProvider documents */
    public function testEveryConfigKeyNamedExists(string $document): void
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
        $known = ['dir', 'deliveryUrl', 'key', 'bundle', 'locale', 'fallbackLocale', 'keyCase', 'legacyPercentPlaceholders', 'staleAfter', 'logger', 'caBundle', 'strict', 'transport', 'apiUrl', 'apiKey', 'now', 'sleep', 'disk'];
        preg_match_all("/'(\w+)'\s*=>/", $text, $m);
        // Lookup and pull options, the old helpers' options, and placeholder or language names in examples.
        $options = ['mappings', 'namespace', 'SEO', 'wait', 'timeout', 'poll', 'maxBytes', 'adoptExisting', 'locale', 'bundle', 'name', 'alt', 'title', 'year', 'count', 'en', 'fr'];
        foreach (array_unique($m[1]) as $key) {
            self::assertContains($key, array_merge($known, $options), $document . " uses '" . $key . "' =>");
        }
    }
}
