<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\Tests\Support\ReleaseBuilder;

final class KeyTest extends LookupCase
{
    private function fixture(): ReleaseBuilder
    {
        return self::leaves(new ReleaseBuilder('7'), 'ns1', ['type' => 'namespace', 'name' => 'default', 'layout' => 'flat'], 'en', [
            'Page.Title' => ['id' => '1', 'value' => 'Welcome'],
            'page.title' => ['id' => '2', 'value' => 'second spelling'],
            'ΟΔΟΣ' => ['id' => '3', 'value' => 'Street'],
            '404' => ['id' => '4', 'value' => 'Not found'],
            '007' => ['id' => '5', 'value' => 'Bond'],
            'empty' => ['id' => '6', 'value' => ''],
            'number' => ['id' => '7', 'value' => 42],
            'list' => ['id' => '8', 'value' => ['a']],
            'no.value' => ['id' => '9'],
            'not.a.leaf' => 'plain string',
        ]);
    }

    public function testExactCaseByDefault(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en']);

        self::assertSame('Welcome', $client->t('Page.Title'));
        self::assertSame('second spelling', $client->t('page.title'));
        self::assertSame('PAGE.TITLE', $client->t('PAGE.TITLE'));
    }

    public function testLowerCaseFoldsBothSidesAndTheFirstNameWins(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en', 'keyCase' => 'lower']);

        self::assertSame('Welcome', $client->t('PAGE.TITLE'));
        self::assertSame('Welcome', $client->t('page.title'));
    }

    public function testLowerCaseAnswersAMissingKeyAsWritten(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en', 'keyCase' => 'lower']);

        self::assertSame('Missing.KEY', $client->t('Missing.KEY'));
        self::assertSame('Welcome', $client->t('PAGE.title'));
    }

    public function testLowerCaseFoldsTheGreekFinalSigma(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en', 'keyCase' => 'lower']);

        self::assertSame('Street', $client->t('οδος'));
        self::assertSame('Street', $client->t('ΟΔΟΣ'));
    }

    public function testANumericLookingKeyIsFound(): void
    {
        $exact = $this->installed($this->fixture(), ['locale' => 'en']);
        $lower = $this->client(['locale' => 'en', 'keyCase' => 'lower']);

        self::assertSame('Not found', $exact->t('404'));
        self::assertSame('Not found', $lower->t('404'));
        self::assertSame('Bond', $exact->t('007'));
        self::assertSame('Bond', $lower->t('007'));
        self::assertSame('7', $exact->t('7'));
    }

    /** @return iterable<string, array{string}> */
    public function unusableValues(): iterable
    {
        yield 'empty' => ['empty'];
        yield 'a number' => ['number'];
        yield 'a list' => ['list'];
        yield 'no value' => ['no.value'];
        yield 'not a leaf' => ['not.a.leaf'];
        yield 'missing' => ['missing'];
    }

    /** @dataProvider unusableValues */
    public function testAnUnusableValueIsAnsweredWithTheKey(string $key): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en']);

        self::assertSame($key, $client->t($key));
        self::assertFalse($client->has($key));
    }

    public function testHasIsTrueOnlyForANonEmptyValue(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en']);

        self::assertTrue($client->has('Page.Title'));
        self::assertTrue($client->has('404'));
    }

    public function testAKeyNamedLikeAnObjectPropertyIsJustAKey(): void
    {
        $release = self::leaves(new ReleaseBuilder('7'), 'ns1', ['type' => 'namespace', 'name' => 'default', 'layout' => 'flat'], 'en', [
            '__proto__' => ['id' => '1', 'value' => 'proto'],
            'constructor' => ['id' => '2', 'value' => 'ctor'],
            '' => ['id' => '3', 'value' => 'empty name'],
        ]);
        $client = $this->installed($release, ['locale' => 'en']);

        self::assertSame('proto', $client->t('__proto__'));
        self::assertSame('ctor', $client->t('constructor'));
    }
}
