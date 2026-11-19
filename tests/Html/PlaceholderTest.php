<?php

declare(strict_types=1);

namespace Tarjim\Tests\Html;

use Tarjim\Client;
use Tarjim\Tests\Lookup\LookupCase;
use Tarjim\Tests\Support\ReleaseBuilder;

final class PlaceholderTest extends LookupCase
{
    private function make(array $overrides = []): Client
    {
        $release = (new ReleaseBuilder('7'))->flat('ns1', 'namespace', 'default', 'en', [
            'greet' => 'Hello {name}, you have {count} messages',
            'twice' => '{name} and {name}',
            'legacy' => 'Hello %%name%%, see %%link%%',
            'mixed' => '{name} / %%name%%',
            'braces' => 'Use {{name}} or { name } or {na me} or {} or {name}',
            'unknown' => 'Hello {nobody} and {name}',
            'arabic' => 'مرحبا {name}',
        ]);

        return $this->installed($release, $overrides + ['locale' => 'en']);
    }

    public function testFillsNamedPlaceholders(): void
    {
        self::assertSame('Hello Ana, you have 3 messages', $this->make()->t('greet', ['name' => 'Ana', 'count' => 3]));
    }

    public function testFillsEveryOccurrence(): void
    {
        self::assertSame('Ana and Ana', $this->make()->t('twice', ['name' => 'Ana']));
    }

    public function testWithoutArgumentsTheTextIsAsWritten(): void
    {
        self::assertSame('Hello {name}, you have {count} messages', $this->make()->t('greet'));
        self::assertSame('Hello {name}, you have {count} messages', $this->make()->t('greet', []));
    }

    public function testAnUnknownPlaceholderStaysAsWritten(): void
    {
        self::assertSame('Hello {nobody} and Ana', $this->make()->t('unknown', ['name' => 'Ana']));
    }

    public function testInsertedTextIsNeverScannedAgain(): void
    {
        self::assertSame('Hello {count}, you have 3 messages', $this->make()->t('greet', ['name' => '{count}', 'count' => 3]));
        self::assertSame('%%name%% and %%name%%', $this->make(['legacyPercentPlaceholders' => true])->t('twice', ['name' => '%%name%%']));
    }

    public function testThePlaceholderShapeIsStrict(): void
    {
        self::assertSame('Use {Ana} or { name } or x or {} or Ana', $this->make()->t('braces', ['name' => 'Ana', 'na me' => 'x', '' => 'y']));
    }

    public function testPercentPlaceholdersOnlyWhenAskedFor(): void
    {
        self::assertSame('Hello %%name%%, see %%link%%', $this->make()->t('legacy', ['name' => 'Ana']));
        self::assertSame('Hello Ana, see %%link%%', $this->make(['legacyPercentPlaceholders' => true])->t('legacy', ['name' => 'Ana']));
    }

    public function testBothShapesWithTheLegacyOption(): void
    {
        self::assertSame('Ana / Ana', $this->make(['legacyPercentPlaceholders' => true])->t('mixed', ['name' => 'Ana']));
    }

    public function testTheKeyTextFallbackIsNeverFilled(): void
    {
        self::assertSame('missing {name}', $this->make()->t('missing {name}', ['name' => 'Ana']));
        self::assertSame('missing %%name%%', $this->make(['legacyPercentPlaceholders' => true])->t('missing %%name%%', ['name' => 'Ana']));
    }

    /** @return iterable<string, array{mixed, string}> */
    public function argumentValues(): iterable
    {
        yield 'an int' => [3, 'Hello 3, you have 9 messages'];
        yield 'a float' => [1.5, 'Hello 1.5, you have 9 messages'];
        yield 'a numeric string' => ['007', 'Hello 007, you have 9 messages'];
        yield 'an empty string' => ['', 'Hello , you have 9 messages'];
        yield 'null' => [null, 'Hello {name}, you have 9 messages'];
        yield 'a bool' => [true, 'Hello {name}, you have 9 messages'];
        yield 'an array' => [['x'], 'Hello {name}, you have 9 messages'];
        yield 'an object' => [new \stdClass(), 'Hello {name}, you have 9 messages'];
    }

    /**
     * @dataProvider argumentValues
     * @param mixed $value
     */
    public function testOnlyTextAndNumbersAreInserted($value, string $expected): void
    {
        self::assertSame($expected, $this->make()->t('greet', ['name' => $value, 'count' => 9]));
    }

    public function testAStringableObjectIsInserted(): void
    {
        $value = new class {
            public function __toString(): string
            {
                return 'Obj';
            }
        };

        self::assertSame('Hello Obj, you have 9 messages', $this->make()->t('greet', ['name' => $value, 'count' => 9]));
    }

    public function testPositionalArgumentsAreIgnored(): void
    {
        self::assertSame('Hello {name}, you have 9 messages', $this->make()->t('greet', ['Ana', 3, 'count' => 9]));
    }

    public function testUnicodeSurvivesSubstitution(): void
    {
        self::assertSame('مرحبا علي', $this->make()->t('arabic', ['name' => 'علي']));
    }
}
