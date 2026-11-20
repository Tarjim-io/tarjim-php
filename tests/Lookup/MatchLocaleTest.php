<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use PHPUnit\Framework\TestCase;
use Tarjim\Lookup\Languages;

/**
 * The same answers as the JS core's matchLocale (tarjim-js packages/core/test/resolver/locale.test.ts),
 * so a release serves the same language to both SDKs. Underscores are read as hyphens first.
 */
final class MatchLocaleTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, string|null, string|null}> */
    public function cases(): iterable
    {
        // ignores case and answers with the available spelling
        yield 'AR' => ['AR', ['en', 'ar'], null, 'ar'];
        yield 'pt-br' => ['pt-br', ['en', 'pt-BR'], null, 'pt-BR'];
        yield 'en-US against en-us' => ['en-US', ['en-us', 'ar'], null, 'en-us'];
        // serves a region variant from the same language and script without a region
        yield 'ar-EG' => ['ar-EG', ['en', 'ar'], null, 'ar'];
        yield 'en-GB' => ['en-GB', ['en', 'ar'], null, 'en'];
        yield 'en-US against en' => ['en-US', ['en'], null, 'en'];
        // finds nothing for a language the list lacks
        yield 'de-DE' => ['de-DE', ['en', 'ar'], null, null];
        yield 'de against nothing' => ['de', [], null, null];
        // keeps Hans and Hant apart, and finds each in its own script
        yield 'zh-HK against Hans' => ['zh-HK', ['zh-Hans', 'en'], null, null];
        yield 'zh-TW against Hans' => ['zh-TW', ['zh-Hans'], null, null];
        yield 'zh-HK against Hant' => ['zh-HK', ['zh-Hant', 'en'], null, 'zh-Hant'];
        yield 'zh-CN' => ['zh-CN', ['zh-Hant', 'zh-Hans'], null, 'zh-Hans'];
        yield 'zh-Hans' => ['zh-Hans', ['zh-Hant', 'zh-Hans'], null, 'zh-Hans'];
        // keeps Latin Serbian apart from Cyrillic Serbian
        yield 'sr-Latn against sr' => ['sr-Latn', ['sr'], null, null];
        yield 'sr against sr-Latn' => ['sr', ['sr-Latn'], null, null];
        yield 'sr-Latn-RS' => ['sr-Latn-RS', ['sr', 'sr-Latn'], null, 'sr-Latn'];
        yield 'sr-RS' => ['sr-RS', ['sr-Latn', 'sr'], null, 'sr'];
        // prefers the exact tag, whatever the order of the list
        yield 'es-ES first' => ['es-ES', ['es', 'es-ES'], null, 'es-ES'];
        yield 'es-ES second' => ['es-ES', ['es-ES', 'es'], null, 'es-ES'];
        // breaks a tie between regional variants with the least specific key, whatever the order
        yield 'es-MX, es first' => ['es-MX', ['es', 'es-ES'], null, 'es'];
        yield 'es-MX, es second' => ['es-MX', ['es-ES', 'es'], null, 'es'];
        // breaks a tie between equally specific variants by sorted order, whatever the order
        yield 'es-MX siblings' => ['es-MX', ['es-ES', 'es-AR'], null, 'es-AR'];
        yield 'es-MX siblings reversed' => ['es-MX', ['es-AR', 'es-ES'], null, 'es-AR'];
        // a sibling named as the fallback wins over the sorted rule
        yield 'es-MX, fallback es-ES' => ['es-MX', ['es-AR', 'es-ES'], 'es-ES', 'es-ES'];
        // skips a malformed tag on either side without throwing
        yield 'not a tag' => ['not a tag!!', ['en'], null, null];
        yield 'empty' => ['', ['en'], null, null];
        yield 'malformed available' => ['en', ['@@', 'en'], null, 'en'];
        yield 'nothing usable' => ['en', ['', '###'], null, null];
        // never invents a tag the available list does not spell
        yield 'en-US invents nothing' => ['en-US', ['en', 'ar', 'zh-Hant', 'sr-Latn'], null, 'en'];
        yield 'EN invents nothing' => ['EN', ['en', 'ar', 'zh-Hant', 'sr-Latn'], null, 'en'];
        yield 'zh-Hant-HK invents nothing' => ['zh-Hant-HK', ['en', 'ar', 'zh-Hant', 'sr-Latn'], null, 'zh-Hant'];
        // language aliases are the same language, as Intl.Locale canonicalises them
        yield 'iw is he' => ['iw', ['he'], null, 'he'];
        yield 'in is id' => ['in-ID', ['id'], null, 'id'];
        // underscores read as hyphens (this package's rule; JS refuses them)
        yield 'fr_CA' => ['fr_CA', ['fr-CA', 'fr'], null, 'fr-CA'];
        yield 'released pt_BR' => ['pt-br', ['pt_BR'], null, 'pt_BR'];
        // scripts inferred by region, as CLDR's likely subtags do
        yield 'zh-TW against zh and zh-Hant' => ['zh-TW', ['zh', 'zh-Hant'], null, 'zh-Hant'];
        yield 'zh-SG is Hans' => ['zh-SG', ['zh', 'zh-Hant'], null, 'zh'];
        yield 'sr-ME is Latin' => ['sr-ME', ['sr', 'sr-Latn'], null, 'sr-Latn'];
        yield 'az-IR is Arabic' => ['az-IR', ['az'], null, null];
        yield 'pa-PK is Arabic' => ['pa-PK', ['pa', 'pa-Arab'], null, 'pa-Arab'];
        yield 'explicit default script' => ['en-Latn-GB', ['en'], null, 'en'];
        yield 'ar-Arab' => ['ar-Arab', ['ar'], null, 'ar'];
        // an extension is dropped, as Intl.Locale's baseName drops it
        yield 'unicode extension' => ['en-u-ca-gregory', ['en'], null, 'en'];
        yield 'private use' => ['fr-CA-x-mine', ['fr-CA'], null, 'fr-CA'];
        yield 'released with an extension' => ['de', ['de-u-co-phonebk'], null, 'de-u-co-phonebk'];
    }

    /**
     * @dataProvider cases
     * @param list<string> $available
     */
    public function testMatchesLikeTheJsCore(string $wanted, array $available, ?string $fallback, ?string $expected): void
    {
        self::assertSame($expected, Languages::match($wanted, $available, $fallback));
    }

    public function testTheChainTriesTheFallbackWholeAfterTheWantedLanguage(): void
    {
        self::assertSame('en', Languages::pick(['en' => 'h1', 'zh-Hans' => 'h2'], 'zh-TW', 'en'));
        self::assertSame('zh-Hant', Languages::pick(['en' => 'h1', 'zh-Hans' => 'h2', 'zh-Hant' => 'h3'], 'zh-TW', 'en'));
        self::assertNull(Languages::pick(['zh-Hans' => 'h2'], 'zh-TW', null));
    }
}
