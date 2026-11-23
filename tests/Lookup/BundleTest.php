<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\RefreshResult;
use Tarjim\Tests\Support\ReleaseBuilder;

final class BundleTest extends LookupCase
{
    private function fixture(): ReleaseBuilder
    {
        return (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello from the namespace'])
            ->flat('ns2', 'namespace', 'backend', 'en', ['hello' => 'Hello from backend'])
            ->flat('cb1', 'custom', 'web', 'en', ['hello' => 'Hello from web']);
    }

    public function testTheDefaultBundleIsTheOneNamedDefault(): void
    {
        self::assertSame('Hello from the namespace', $this->installed($this->fixture(), ['locale' => 'en'])->t('hello'));
    }

    public function testTheConfiguredBundleIsUsed(): void
    {
        self::assertSame('Hello from backend', $this->installed($this->fixture(), ['locale' => 'en', 'bundle' => 'backend'])->t('hello'));
    }

    public function testALookupOptionPicksAnotherBundle(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en']);

        self::assertSame('Hello from web', $client->t('hello', [], ['bundle' => 'web']));
    }

    public function testACustomBundleWinsOverANamespaceOfTheSameName(): void
    {
        $release = $this->fixture()->flat('cb2', 'custom', 'backend', 'en', ['hello' => 'Hello from the custom backend']);

        self::assertSame('Hello from the custom backend', $this->installed($release, ['locale' => 'en', 'bundle' => 'backend'])->t('hello'));
    }

    public function testABundleNotInTheReleaseAnswersWithTheKey(): void
    {
        $client = $this->installed($this->fixture(), ['locale' => 'en']);

        self::assertSame('hello', $client->t('hello', [], ['bundle' => 'missing']));
        self::assertSame('Hello from the namespace', $client->t('hello'));
    }

    public function testAConfiguredBundleMissingFromTheReleaseStopsTheInstall(): void
    {
        $this->installed($this->fixture(), ['locale' => 'en']);
        $this->laterBy(61);
        $next = (new ReleaseBuilder('8'))->flat('ns2', 'namespace', 'backend', 'en', ['hello' => 'v8']);
        $this->serve($next);
        $result = $this->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertStringContainsString('default', $result->message);
        self::assertTrue($result->copyHeld);
        self::assertSame('7', $this->client()->status()->releaseId);
    }

    public function testANestedConfiguredBundleStopsTheInstall(): void
    {
        $release = self::leaves(new ReleaseBuilder('7'), 'cb1', ['type' => 'custom', 'name' => 'web', 'layout' => 'nested'], 'en', []);
        $this->serve($release);
        $result = $this->refresh([], ['bundle' => 'web']);

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertStringContainsString('web', $result->message);
        self::assertStringContainsStringIgnoringCase('flat', $result->message);
        self::assertNull($this->client()->status()->releaseId);
    }

    public function testAFirstInstallWithoutTheConfiguredBundleInstallsNothing(): void
    {
        $this->serve((new ReleaseBuilder('7'))->flat('ns2', 'namespace', 'backend', 'en', ['hello' => 'x']));
        $result = $this->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertFalse($result->copyHeld);
    }

    public function testANestedBundleThatIsNotConfiguredIsSkipped(): void
    {
        $release = self::leaves($this->fixture(), 'cb9', ['type' => 'custom', 'name' => 'app', 'layout' => 'nested'], 'en', []);
        $client = $this->installed($release, ['locale' => 'en']);

        self::assertSame('Hello from the namespace', $client->t('hello'));
        self::assertSame('hello', $client->t('hello', [], ['bundle' => 'app']));
    }

    public function testASkippedNestedBundleIsLogged(): void
    {
        $release = self::leaves($this->fixture(), 'cb9', ['type' => 'custom', 'name' => 'app', 'layout' => 'nested'], 'en', []);
        $this->installed($release, ['locale' => 'en']);
        $warnings = array_values(array_filter($this->logged, static function (array $entry): bool {
            return in_array($entry[0], ['warning', 'notice', 'info'], true) && strpos($entry[1], 'app') !== false;
        }));

        self::assertCount(1, $warnings);
    }

    public function testANestedCustomBundleHidesAFlatNamespaceOfTheSameNameForLookupsToo(): void
    {
        $release = self::leaves($this->fixture(), 'cb9', ['type' => 'custom', 'name' => 'backend', 'layout' => 'nested'], 'en', []);
        $client = $this->installed($release, ['locale' => 'en']);

        self::assertSame('hello', $client->t('hello', [], ['bundle' => 'backend']));
        self::assertSame('Hello from the namespace', $client->t('hello'));
    }

    public function testALayoutOtherThanFlatIsNeverServed(): void
    {
        $release = self::leaves($this->fixture(), 'cb9', ['type' => 'custom', 'name' => 'odd', 'layout' => 'Flat'], 'en', [
            'hello' => ['id' => '1', 'value' => 'odd text'],
        ]);
        $client = $this->installed($release, ['locale' => 'en']);

        self::assertSame('hello', $client->t('hello', [], ['bundle' => 'odd']));
        self::assertSame('Hello from the namespace', $client->t('hello'));
    }

    public function testAConfiguredBundleWithAnUnknownLayoutIsReportedAsNotFlat(): void
    {
        $release = self::leaves(new ReleaseBuilder('7'), 'cb1', ['type' => 'custom', 'name' => 'web', 'layout' => 'columns'], 'en', [
            'hello' => ['id' => '1', 'value' => 'x'],
        ]);
        $this->serve($release);
        $result = $this->refresh([], ['bundle' => 'web']);

        self::assertSame(\Tarjim\RefreshResult::MISCONFIGURED, $result->status);
        self::assertStringContainsString('web', $result->message);
        self::assertStringNotContainsString('nested', $result->message);
        self::assertStringContainsStringIgnoringCase('flat', $result->message);
    }

    public function testAMissingConfiguredBundleIsReportedWithTheBundlesTheReleaseHas(): void
    {
        $release = (new ReleaseBuilder('10005'))
            ->flat('cb1', 'custom', 'spa', 'en', ['hello' => 'Hello'])
            ->flat('ns2', 'namespace', 'emails', 'en', ['hello' => 'Hello']);
        $this->serve($release);
        $result = $this->refresh();

        self::assertSame(RefreshResult::MISCONFIGURED, $result->status);
        self::assertStringContainsString("the configured bundle 'default' is not in release 10005; it has: emails, spa", $result->message);
    }
}
