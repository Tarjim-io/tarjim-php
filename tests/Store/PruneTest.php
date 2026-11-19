<?php

declare(strict_types=1);

namespace Tarjim\Tests\Store;

use Tarjim\Tests\Support\StoreCase;

final class PruneTest extends StoreCase
{
    /** @return list<string> */
    private function objects(): array
    {
        $names = array_map('basename', glob($this->dir . '/objects/*.json') ?: []);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function names(\Tarjim\Tests\Support\ReleaseBuilder $release): array
    {
        return array_map(static function (string $hash): string {
            return $hash . '.json';
        }, array_keys($release->files));
    }

    private function install(\Tarjim\Tests\Support\ReleaseBuilder $release, int $after): void
    {
        $this->laterBy($after);
        $this->serve($release);
        self::assertSame(\Tarjim\RefreshResult::UPDATED, $this->refresh()->status);
    }

    public function testKeepsTheCurrentAndThePreviousRelease(): void
    {
        $seven = $this->release('7', ['a' => 'seven'], ['a' => 'sept']);
        $eight = $this->release('8', ['a' => 'eight'], ['a' => 'huit']);
        $nine = $this->release('9', ['a' => 'nine'], ['a' => 'neuf']);
        $this->install($seven, 0);
        $this->install($eight, 90000);
        $this->install($nine, 90000);
        $this->laterBy(90000);
        $this->refresh();
        $objects = $this->objects();

        foreach (array_merge(self::names($eight), self::names($nine)) as $name) {
            self::assertContains($name, $objects);
        }
        foreach (self::names($seven) as $name) {
            self::assertNotContains($name, $objects);
        }
        self::assertSame([], glob($this->dir . '/releases/7-*.json') ?: []);
        self::assertCount(1, glob($this->dir . '/releases/8-*.json') ?: []);
    }

    public function testKeepsAnythingYoungerThanADay(): void
    {
        $seven = $this->release('7', ['a' => 'seven'], ['a' => 'sept']);
        $this->install($seven, 0);
        $this->install($this->release('8', ['a' => 'eight'], ['a' => 'huit']), 3600);
        $this->install($this->release('9', ['a' => 'nine'], ['a' => 'neuf']), 3600);

        foreach (self::names($seven) as $name) {
            self::assertContains($name, $this->objects(), 'a process started on release 7 may still read it');
        }
    }

    public function testKeepsAFileTheCurrentReleaseShares(): void
    {
        $seven = $this->release('7', ['a' => 'same'], ['a' => 'sept']);
        $this->install($seven, 0);
        $this->install($this->release('8', ['a' => 'same'], ['a' => 'huit']), 90000);
        $this->install($this->release('9', ['a' => 'same'], ['a' => 'neuf']), 90000);
        $this->laterBy(90000);
        $this->refresh();
        $shared = (new \Tarjim\Tests\Support\ReleaseBuilder('x'))->flat('ns1', 'namespace', 'default', 'en', ['a' => 'same']);

        self::assertContains(self::names($shared)[0], $this->objects());
    }

    public function testLeavesTempFilesOfAnInterruptedWriteToBeCleanedLater(): void
    {
        $this->install($this->release('7'), 0);
        $stray = $this->dir . '/objects/.' . str_repeat('a', 64) . '.json.deadbeef.tmp';
        file_put_contents($stray, 'half');
        touch($stray, $this->clock->now);
        $this->install($this->release('8', ['hello' => 'Hi']), 90000);
        $this->laterBy(90000);
        $this->refresh();

        self::assertFileDoesNotExist($stray);
    }
}
