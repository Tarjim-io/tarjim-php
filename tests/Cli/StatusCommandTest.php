<?php

declare(strict_types=1);

namespace Tarjim\Tests\Cli;

use Tarjim\Wire\Response;

/** `status`: 0 fresh copy, 1 stale copy, 2 misconfigured, 4 no copy. It never calls Tarjim. */
final class StatusCommandTest extends CliCase
{
    public function testNothingHeldIsFour(): void
    {
        self::assertSame(4, $this->cli(['status', '--config={config}']));
        self::assertSame([], $this->fake->requests);
    }

    public function testAFreshCopyIsZero(): void
    {
        $this->serve($this->release());
        $this->cli(['refresh', '--config={config}']);
        $this->fake->requests = [];

        self::assertSame(0, $this->cli(['status', '--config={config}']));
        self::assertSame([], $this->fake->requests);
        self::assertStringContainsString('7', $this->out);
    }

    public function testAStaleCopyIsOne(): void
    {
        $this->serve($this->release());
        $this->cli(['refresh', '--config={config}']);
        $this->laterBy(86401);
        $this->fake->on(self::DELIVERY, new Response(503));

        self::assertSame(1, $this->cli(['status', '--config={config}']));
    }

    public function testABadConfigIsTwo(): void
    {
        self::assertSame(2, $this->cli(['status', '--config=' . $this->root . '/missing.php']));
        self::assertSame(2, $this->cli(['status', '--config={config}'], ['dir' => 'relative/dir']));
    }

    public function testStatusWorksWithoutAKey(): void
    {
        $this->serve($this->release());
        $this->cli(['refresh', '--config={config}']);
        $config = $this->config();
        unset($config['key'], $config['deliveryUrl']);

        self::assertSame(0, $this->cli(['status', '--config={config}'], ['key' => null, 'deliveryUrl' => null]));
    }

    public function testOutputNeverCarriesTheKey(): void
    {
        $this->serve($this->release());
        $this->cli(['refresh', '--config={config}']);
        $this->cli(['status', '--config={config}']);

        self::assertStringNotContainsString(self::KEY, $this->out . $this->err);
    }
}
