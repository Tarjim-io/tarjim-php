<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\Tests\Support\ReleaseBuilder;

final class LanguageTest extends LookupCase
{
    public function testServesTheConfiguredLanguage(): void
    {
        $client = $this->installed($this->languages(), ['locale' => 'fr']);

        self::assertSame('Bonjour', $client->t('hello'));
    }

    public function testALookupOptionOverridesTheConfiguredLanguage(): void
    {
        $client = $this->installed($this->languages(), ['locale' => 'fr']);

        self::assertSame('مرحبا', $client->t('hello', [], ['locale' => 'ar']));
    }

    public function testMatchesTheTagWhateverItsCase(): void
    {
        $client = $this->installed($this->languages());

        self::assertSame('Allô', $client->t('hello', [], ['locale' => 'FR-ca']));
        self::assertSame('Bonjour', $client->t('hello', [], ['locale' => 'FR']));
    }

    public function testAnUnderscoreMatchesAHyphen(): void
    {
        $client = $this->installed($this->languages());

        self::assertSame('Allô', $client->t('hello', [], ['locale' => 'fr_CA']));
    }

    public function testAReleaseTagWithAnUnderscoreIsMatchedToo(): void
    {
        $release = (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello'])
            ->flat('ns1', 'namespace', 'default', 'pt_BR', ['hello' => 'Olá']);
        $client = $this->installed($release);

        self::assertSame('Olá', $client->t('hello', [], ['locale' => 'pt-br']));
    }

    public function testARegionWithoutItsOwnTextFallsBackToItsLanguage(): void
    {
        $client = $this->installed($this->languages());

        self::assertSame('Bonjour', $client->t('hello', [], ['locale' => 'fr-BE']));
    }

    public function testALanguageNotReleasedFallsBackToTheFallbackLanguage(): void
    {
        $client = $this->installed($this->languages(), ['fallbackLocale' => 'en']);

        self::assertSame('Hello', $client->t('hello', [], ['locale' => 'de']));
    }

    public function testWithNoFallbackTheKeyIsTheAnswer(): void
    {
        $client = $this->installed($this->languages());

        self::assertSame('hello', $client->t('hello', [], ['locale' => 'de']));
        self::assertSame('Bonjour', $client->t('hello', [], ['locale' => 'fr']));
    }

    public function testAKeyMissingInTheChosenLanguageIsNeverTakenFromAnother(): void
    {
        $client = $this->installed($this->languages(), ['locale' => 'fr', 'fallbackLocale' => 'en']);

        self::assertSame('only.en', $client->t('only.en'));
        self::assertSame('Bonjour', $client->t('hello'));
    }

    public function testAnEmptyValueIsAnsweredWithTheKeyNotAnotherLanguage(): void
    {
        $client = $this->installed($this->languages(), ['locale' => 'fr', 'fallbackLocale' => 'en']);

        self::assertSame('empty.fr', $client->t('empty.fr'));
        self::assertSame('Not empty', $client->t('empty.fr', [], ['locale' => 'en']));
    }

    public function testARegionWithItsOwnTextIsServedWholeEvenWhereItLacksAKey(): void
    {
        $client = $this->installed($this->languages(), ['fallbackLocale' => 'en']);

        self::assertSame('empty.fr', $client->t('empty.fr', [], ['locale' => 'fr-CA']));
        self::assertSame('Allô', $client->t('hello', [], ['locale' => 'fr-CA']));
    }

    public function testLocalesListsTheBundlesLanguages(): void
    {
        $client = $this->installed($this->languages());
        $locales = $client->locales();
        sort($locales);

        self::assertSame(['ar', 'en', 'fr', 'fr-CA'], $locales);
    }

    public function testArabicRoundTripsByteForByte(): void
    {
        $client = $this->installed($this->languages());

        self::assertSame("\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}", $client->t('hello', [], ['locale' => 'ar']));
    }
}
