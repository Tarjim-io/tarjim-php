<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tarjim\Client;
use Tarjim\RefreshResult;
use Tarjim\Tests\Support\FakeTransport;
use Tarjim\Tests\Support\ReleaseBuilder;

final class ClientConfigTest extends TestCase
{
    private const KEY = ReleaseBuilder::KEY;

    /** @var list<array{string, string, array<string, mixed>}> */
    private $logged = [];

    /** @var FakeTransport */
    private $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
    }

    /** @param array<string, mixed> $config */
    private function create(array $config): Client
    {
        return Client::create($config + [
            'transport' => $this->fake,
            'logger' => function (string $level, string $message, array $context): void {
                $this->logged[] = [$level, $message, $context];
            },
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function badConfigs(): iterable
    {
        $dir = sys_get_temp_dir() . '/tarjim-config-test';
        $url = 'https://delivery.example.test/delivery/dst_test/meta';
        yield 'nothing' => [[]];
        yield 'empty values' => [['dir' => '', 'deliveryUrl' => '', 'key' => '']];
        yield 'wrong types' => [['dir' => ['x'], 'deliveryUrl' => 42, 'key' => new \stdClass(), 'staleAfter' => 'soon', 'keyCase' => 7]];
        yield 'plain http url' => [['dir' => $dir, 'deliveryUrl' => 'http://delivery.example.test/meta', 'key' => self::KEY]];
        yield 'url with a query' => [['dir' => $dir, 'deliveryUrl' => $url . '?apikey=' . self::KEY, 'key' => self::KEY]];
        yield 'key with a line break' => [['dir' => $dir, 'deliveryUrl' => $url, 'key' => self::KEY . "\r\nX-Injected: 1"]];
        yield 'no key' => [['dir' => $dir, 'deliveryUrl' => $url]];
        yield 'logger not callable' => [['dir' => $dir, 'deliveryUrl' => $url, 'key' => self::KEY, 'logger' => 'no_such_function_here']];
        yield 'unknown key case' => [['dir' => $dir, 'deliveryUrl' => $url, 'key' => self::KEY, 'keyCase' => 'upper']];
    }

    /**
     * @dataProvider badConfigs
     * @param array<string, mixed> $config
     */
    public function testABadConfigNeverThrowsFromCreateOrStatus(array $config): void
    {
        $client = $this->create($config);
        $client->status();

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider badConfigs
     * @param array<string, mixed> $config
     */
    public function testABadConfigNeverThrowsFromALookup(array $config): void
    {
        self::assertSame('page.title', $this->create($config)->t('page.title'));
    }

    public function testABadDeliveryUrlIsMisconfiguredAndCallsNobody(): void
    {
        $result = $this->create(['dir' => sys_get_temp_dir(), 'deliveryUrl' => 'http://delivery.example.test/meta', 'key' => self::KEY])->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testAMissingKeyIsMisconfiguredAndCallsNobody(): void
    {
        $result = $this->create(['dir' => sys_get_temp_dir(), 'deliveryUrl' => 'https://delivery.example.test/delivery/dst_test/meta'])->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testAKeyThatCouldInjectAHeaderIsMisconfigured(): void
    {
        $result = $this->create([
            'dir' => sys_get_temp_dir(),
            'deliveryUrl' => 'https://delivery.example.test/delivery/dst_test/meta',
            'key' => self::KEY . "\r\nX-Injected: 1",
        ])->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertSame([], $this->fake->requests);
    }

    public function testABadConfigIsLoggedAsAnError(): void
    {
        $this->create(['deliveryUrl' => 'nope']);

        self::assertNotEmpty(array_filter($this->logged, static function (array $entry): bool {
            return $entry[0] === 'error';
        }));
    }

    public function testTheKeyIsNeverLoggedOrReported(): void
    {
        $client = $this->create(['dir' => '', 'deliveryUrl' => 'https://delivery.example.test/meta?apikey=' . self::KEY, 'key' => self::KEY . "\n"]);
        $result = $client->refresh();

        self::assertStringNotContainsString(self::KEY, serialize($this->logged));
        self::assertStringNotContainsString(self::KEY, $result->message);
        self::assertStringNotContainsString(self::KEY, serialize($client->status()));
    }

    public function testStrictModeKeepsTheKeyOutOfTheTraceArguments(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            Client::create(['strict' => true, 'deliveryUrl' => 'nope', 'key' => self::KEY, 'dir' => '/srv/' . self::KEY]);
            self::fail('strict mode accepted a bad config');
        } catch (\InvalidArgumentException $error) {
            $frames = array_filter($error->getTrace(), static function (array $frame): bool {
                return strpos($frame['class'] ?? '', 'Tarjim\\') === 0 && strpos($frame['class'] ?? '', 'Tarjim\\Tests\\') !== 0;
            });
            self::assertNotEmpty($frames);
            foreach ($frames as $frame) {
                self::assertFalse(strpos((string) json_encode($frame['args'] ?? []), self::KEY) !== false, 'the key is an argument of ' . $frame['class'] . '::' . $frame['function']);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    public function testDumpingTheClientShowsNoKey(): void
    {
        $client = Client::create(['dir' => sys_get_temp_dir(), 'deliveryUrl' => 'https://delivery.example.test/delivery/dst_test/meta', 'key' => self::KEY]);
        ob_start();
        var_dump($client);
        $dumps = print_r($client, true) . (string) ob_get_clean();

        // Not assertStringNotContainsString: its failure message would print the whole dump.
        self::assertFalse(strpos($dumps, self::KEY) !== false, 'the key appears in a dump of the client');
    }

    public function testDumpingTheDeliveryClientShowsNoKey(): void
    {
        $client = new \Tarjim\Wire\DeliveryClient(new FakeTransport(), 'https://delivery.example.test/delivery/dst_test/meta', self::KEY);

        self::assertFalse(strpos(print_r($client, true), self::KEY) !== false, 'the key appears in a dump of the delivery client');
    }

    public function testStrictModeThrowsWithoutTheKeyInTheMessage(): void
    {
        try {
            Client::create(['strict' => true, 'deliveryUrl' => 'https://delivery.example.test/meta?apikey=' . self::KEY, 'key' => self::KEY]);
            self::fail('strict mode accepted a bad config');
        } catch (\InvalidArgumentException $error) {
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getTraceAsString());
        }
    }

    public function testALoggerThatThrowsDoesNotBreakCreate(): void
    {
        Client::create(['deliveryUrl' => 'nope', 'logger' => static function (): void {
            throw new \RuntimeException('logger down');
        }]);

        $this->addToAssertionCount(1);
    }
}
