<?php

declare(strict_types=1);

namespace Tarjim\Tests\Cli;

use Tarjim\Cli\Application;
use Tarjim\Tests\Support\LockHolder;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Wire\Response;

/**
 * `refresh` exit codes: the outcome of this run against whether a usable copy is held afterwards.
 *
 *                         | plain | --require-copy, copy | --require-copy, none | --wait (ran out)
 *   updated / unchanged   |   0   |          0           |          -           |  0
 *   notDue / busy         |   0   |          0           |          4           |  3
 *   unreleased            |   0   |          0           |          4           |  3, or 4 with --require-copy
 *   failed                |   1   |          0           |          4           |  1 (not waited on)
 *   misconfigured         |   2   |          0           |          4           |  2
 *
 * A misconfigured config file (missing, not returning an array) is 2 whatever the flags.
 */
final class RefreshExitCodesTest extends CliCase
{
    private function holdSeven(): void
    {
        $this->serve($this->release());
        self::assertSame(Application::OK, $this->cli(['refresh', '--config={config}']));
        $this->laterBy(61);
    }

    private function unreleased(): void
    {
        $this->fake->on(self::DELIVERY, new Response(404, [], '{"code":"delivery.stage_unreleased"}'));
    }

    public function testUpdatedIsZero(): void
    {
        $this->serve($this->release());

        self::assertSame(0, $this->cli(['refresh', '--config={config}']));
    }

    public function testUnchangedIsZero(): void
    {
        $this->holdSeven();

        self::assertSame(0, $this->cli(['refresh', '--config={config}']));
    }

    public function testNotDueIsZero(): void
    {
        $this->holdSeven();
        $this->laterBy(-30);

        self::assertSame(0, $this->cli(['refresh', '--config={config}']));
    }

    public function testBusyIsZero(): void
    {
        $this->holdSeven();
        $holder = new LockHolder($this->dir . '/lock', 5);
        $code = $this->cli(['refresh', '--config={config}']);
        $holder->release();

        self::assertSame(0, $code);
    }

    public function testUnreleasedIsZero(): void
    {
        $this->unreleased();

        self::assertSame(0, $this->cli(['refresh', '--config={config}']));
    }

    public function testFailedIsOne(): void
    {
        $this->holdSeven();
        $this->fake->on(self::DELIVERY, new Response(503));

        self::assertSame(1, $this->cli(['refresh', '--config={config}']));
    }

    public function testFailedWithNothingHeldIsOne(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503));

        self::assertSame(1, $this->cli(['refresh', '--config={config}']));
    }

    public function testARefusedKeyIsTwo(): void
    {
        $this->fake->on(self::DELIVERY, new Response(401, [], '{"code":"delivery.unknown_key"}'));

        self::assertSame(2, $this->cli(['refresh', '--config={config}']));
    }

    public function testARelativeDirIsTwo(): void
    {
        self::assertSame(2, $this->cli(['refresh', '--config={config}'], ['dir' => 'storage/tarjim']));
        self::assertSame([], $this->fake->requests);
    }

    public function testAMissingConfigFileIsTwo(): void
    {
        self::assertSame(2, $this->cli(['refresh', '--config=' . $this->root . '/missing.php']));
    }

    public function testAConfigFileThatReturnsNoArrayIsTwo(): void
    {
        file_put_contents($this->root . '/bad.php', "<?php\nreturn 'nope';\n");

        self::assertSame(2, $this->cli(['refresh', '--config=' . $this->root . '/bad.php']));
    }

    public function testNoConfigOptionIsTwo(): void
    {
        self::assertSame(2, $this->cli(['refresh']));
    }

    public function testAnUnknownCommandOrOptionIsTwo(): void
    {
        self::assertSame(2, $this->cli(['frobnicate', '--config={config}']));
        self::assertSame(2, $this->cli(['refresh', '--config={config}', '--force']));
        self::assertSame(2, $this->cli(['refresh', '--config={config}', '--wait=soon']));
        self::assertSame(2, $this->cli([]));
    }

    public function testRequireCopyIsZeroWhenACopyIsHeldWhateverHappened(): void
    {
        $this->holdSeven();
        $this->fake->on(self::DELIVERY, new Response(503));
        self::assertSame(0, $this->cli(['refresh', '--config={config}', '--require-copy']));

        $this->laterBy(61);
        $this->fake->on(self::DELIVERY, new Response(401, [], '{"code":"delivery.unknown_key"}'));
        self::assertSame(0, $this->cli(['refresh', '--config={config}', '--require-copy']));
    }

    public function testRequireCopyIsZeroAfterAFirstInstall(): void
    {
        $this->serve($this->release());

        self::assertSame(0, $this->cli(['refresh', '--config={config}', '--require-copy']));
    }

    public function testRequireCopyIsFourWithNoCopy(): void
    {
        $this->unreleased();
        self::assertSame(4, $this->cli(['refresh', '--config={config}', '--require-copy']));

        $this->laterBy(61);
        $this->fake->on(self::DELIVERY, new Response(503));
        self::assertSame(4, $this->cli(['refresh', '--config={config}', '--require-copy']));
    }

    public function testRequireCopyIsFourWhenTheHeldCopyIsBroken(): void
    {
        $this->holdSeven();
        foreach (glob($this->dir . '/objects/*.json') ?: [] as $file) {
            unlink($file);
        }
        $this->fake->on(self::DELIVERY, new Response(503));

        self::assertSame(4, $this->cli(['refresh', '--config={config}', '--require-copy']));
    }

    public function testRequireCopyWithABadConfigFileIsTwo(): void
    {
        self::assertSame(2, $this->cli(['refresh', '--config=' . $this->root . '/missing.php', '--require-copy']));
    }

    public function testWaitThatRunsOutIsThree(): void
    {
        $this->unreleased();

        self::assertSame(3, $this->cli(['refresh', '--config={config}', '--wait=300']));
    }

    public function testWaitThatRunsOutWithRequireCopyAndNoCopyIsFour(): void
    {
        $this->unreleased();

        self::assertSame(4, $this->cli(['refresh', '--config={config}', '--wait=300', '--require-copy']));
    }

    public function testWaitOverAStoredRetryAfterThatEndsInTimeIsZero(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => '120']));
        self::assertSame(1, $this->cli(['refresh', '--config={config}']));
        $this->serve($this->release());

        self::assertSame(0, $this->cli(['refresh', '--config={config}', '--wait=300']));
    }

    public function testWaitOverAStoredRetryAfterLongerThanTheLimitIsThree(): void
    {
        $this->fake->on(self::DELIVERY, new Response(503, ['retry-after' => '900']));
        $this->cli(['refresh', '--config={config}']);
        $this->serve($this->release());

        self::assertSame(3, $this->cli(['refresh', '--config={config}', '--wait=300']));
    }

    public function testAFailureUnderWaitIsOne(): void
    {
        $this->fake->on(self::DELIVERY, new Response(500));

        self::assertSame(1, $this->cli(['refresh', '--config={config}', '--wait=300']));
    }

    public function testMisconfiguredUnderWaitIsTwo(): void
    {
        $this->fake->on(self::DELIVERY, new Response(403, [], '{"code":"x"}'));

        self::assertSame(2, $this->cli(['refresh', '--config={config}', '--wait=300']));
    }

    public function testOutputNamesTheOutcomeAndNeverTheKey(): void
    {
        $this->serve($this->release());
        $this->cli(['refresh', '--config={config}']);

        self::assertStringContainsString('updated', $this->out . $this->err);
        self::assertStringContainsString('7', $this->out . $this->err);
        self::assertStringNotContainsString(self::KEY, $this->out . $this->err);
    }

    public function testAnErrorGoesToStandardError(): void
    {
        $this->fake->on(self::DELIVERY, new Response(401, [], '{"code":"x"}'));
        $this->cli(['refresh', '--config={config}']);

        self::assertNotSame('', $this->err);
        self::assertStringNotContainsString(self::KEY, $this->err);
    }

    public function testAConfigFileThatThrowsIsTwoAndKeepsItsMessageOut(): void
    {
        file_put_contents($this->root . '/throws.php', "<?php\nthrow new RuntimeException('" . ReleaseBuilder::KEY . "');\n");

        self::assertSame(2, $this->cli(['refresh', '--config=' . $this->root . '/throws.php']));
        self::assertStringNotContainsString(self::KEY, $this->out . $this->err);
    }

    public function testARelativeDirWithRequireCopyIsTwo(): void
    {
        self::assertSame(2, $this->cli(['refresh', '--config={config}', '--require-copy'], ['dir' => 'storage/tarjim']));
    }

    public function testStrictModeDoesNotChangeTheExitCodes(): void
    {
        self::assertSame(2, $this->cli(['refresh', '--config={config}'], ['strict' => true, 'dir' => 'storage/tarjim']));
        self::assertSame(2, $this->cli(['refresh', '--config={config}'], ['strict' => true, 'key' => '']));
        $this->holdSeven();
        $this->fake->on(self::DELIVERY, new Response(503));
        self::assertSame(0, $this->cli(['refresh', '--config={config}', '--require-copy'], ['strict' => true, 'keyCase' => 'upper']));
    }
}
