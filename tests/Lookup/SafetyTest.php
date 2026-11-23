<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\Client;

/** Lookups never call Tarjim, never write, never throw, and never log staleness. */
final class SafetyTest extends LookupCase
{
    private function everyLookup(Client $client): void
    {
        $client->t('hello');
        $client->t('hello', [], ['locale' => 'fr', 'bundle' => 'default']);
        $client->has('hello');
        $client->image('hello');
        $client->locales();
    }

    public function testLookupsNeitherCallTarjimNorWrite(): void
    {
        $client = $this->installed($this->release(), ['locale' => 'en']);
        $before = $this->storeFiles();
        $mtimes = array_map(function (string $name): int {
            return (int) filemtime($this->dir . '/' . $name);
        }, array_keys($before));
        $this->laterBy(90000);
        $this->everyLookup($client);
        $this->everyLookup($this->client(['locale' => 'en']));

        self::assertSame([], $this->fake->requests);
        self::assertSame($before, $this->storeFiles());
        clearstatcache();
        self::assertSame($mtimes, array_map(function (string $name): int {
            return (int) filemtime($this->dir . '/' . $name);
        }, array_keys($before)));
    }

    public function testAStaleCopyIsServedWithoutLogging(): void
    {
        $client = $this->installed($this->release(), ['locale' => 'en']);
        $this->laterBy(10 * 86400);
        $this->logged = [];
        for ($i = 0; $i < 20; $i++) {
            self::assertSame('Hello', $client->t('hello'));
        }

        self::assertSame([], $this->logged);
    }

    public function testNoStoreAnswersWithTheKey(): void
    {
        $client = $this->client(['locale' => 'en']);

        self::assertSame('hello', $client->t('hello'));
        self::assertFalse($client->has('hello'));
        self::assertSame([], $client->locales());
        self::assertDirectoryDoesNotExist($this->dir);
    }

    /** @return iterable<string, array{callable(string): void}> */
    public function corruptions(): iterable
    {
        yield 'object is not json' => [static function (string $dir): void {
            foreach (glob($dir . '/objects/*.json') ?: [] as $file) {
                file_put_contents($file, '{"schemaVersion":1,"keys":');
            }
        }];
        yield 'object of another schema' => [static function (string $dir): void {
            foreach (glob($dir . '/objects/*.json') ?: [] as $file) {
                file_put_contents($file, '{"schemaVersion":2,"keys":{"hello":{"value":"x"}}}');
            }
        }];
        yield 'object keys are a list' => [static function (string $dir): void {
            foreach (glob($dir . '/objects/*.json') ?: [] as $file) {
                file_put_contents($file, '{"schemaVersion":1,"keys":[1,2]}');
            }
        }];
        yield 'objects removed' => [static function (string $dir): void {
            foreach (glob($dir . '/objects/*.json') ?: [] as $file) {
                unlink($file);
            }
        }];
        yield 'index removed' => [static function (string $dir): void {
            foreach (glob($dir . '/releases/*.json') ?: [] as $file) {
                unlink($file);
            }
        }];
        yield 'index is garbage' => [static function (string $dir): void {
            foreach (glob($dir . '/releases/*.json') ?: [] as $file) {
                file_put_contents($file, "\0\1\2");
            }
        }];
        yield 'pointer is garbage' => [static function (string $dir): void {
            file_put_contents($dir . '/current.json', '{"release":"../../../etc/passwd"}');
        }];
        yield 'pointer is a list' => [static function (string $dir): void {
            file_put_contents($dir . '/current.json', '[]');
        }];
        yield 'store unreadable' => [static function (string $dir): void {
            chmod($dir, 0000);
        }];
    }

    /**
     * @dataProvider corruptions
     * @param callable(string): void $corrupt
     */
    public function testACorruptStoreAnswersWithTheKeyAndNeverThrows(callable $corrupt): void
    {
        $this->installed($this->release(), ['locale' => 'en']);
        $corrupt($this->dir);
        $client = $this->client(['locale' => 'en']);
        try {
            self::assertSame('hello', $client->t('hello'));
            self::assertFalse($client->has('hello'));
            self::assertNull($client->image('hello'));
            $client->locales();
        } finally {
            @chmod($this->dir, 0755);
        }
    }

    public function testABadConfigNeverStopsALookup(): void
    {
        $this->installed($this->release(), ['locale' => 'en']);
        $client = Client::create(['dir' => $this->dir, 'locale' => ['x'], 'bundle' => 5, 'keyCase' => 'shout', 'fallbackLocale' => new \stdClass()]);

        self::assertSame('hello', $client->t('hello'));
        self::assertSame('Hello', $client->t('hello', [], ['locale' => 'en', 'bundle' => 'default']));
    }

    public function testLookupOptionsOfTheWrongTypeAreIgnored(): void
    {
        $client = $this->installed($this->release(), ['locale' => 'en']);

        self::assertSame('Hello', $client->t('hello', [], ['locale' => 5, 'bundle' => ['x']]));
    }
}
