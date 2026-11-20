<?php

declare(strict_types=1);

namespace Tarjim\Tests\Docs;

use Tarjim\Client;
use Tarjim\Tests\Lookup\LookupCase;
use Tarjim\Tests\Support\ReleaseBuilder;

/**
 * The helper file the migration guide tells an app to keep (examples/tarjim-helpers.php): the old
 * client's global functions mapped onto the new client.
 */
final class HelperFileTest extends LookupCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/examples/tarjim-helpers.php';
        $release = (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'backend', 'en', [
                'greeting' => 'Hello <b>%%name%%</b>',
                'title' => 'Tom & Jerry\'s "page"',
                'unsafe' => 'Hi <script>alert(1)</script>there',
                'home_endpoint' => 'home',
                'page_open_graph' => '{"og:title":"Title & more","og:description":"<desc>"}',
            ])
            ->flat('ns1', 'namespace', 'backend', 'fr', [
                'greeting' => 'Bonjour <b>%%name%%</b>',
                'home_endpoint' => 'accueil',
            ])
            ->flat('ns2', 'namespace', 'rccaq-backend', 'en', ['greeting' => 'Hello from rccaq'])
            ->slice('ns1', ['type' => 'namespace', 'name' => 'backend', 'layout' => 'flat', 'namespaces' => []], 'ar', [
                'schemaVersion' => 1, 'bundle' => 'ns1', 'locale' => 'ar', 'keys' => [
                    'greeting' => ['id' => '1', 'value' => 'مرحبا'],
                    'logo' => ['id' => '2', 'type' => 'image', 'value' => 'https://cdn.example.test/logo.png', 'attributes' => ['alt' => 'Logo "x"', 'onerror' => 'alert(1)']],
                ],
            ]);
        $this->installed($release, ['bundle' => 'backend', 'keyCase' => 'lower', 'legacyPercentPlaceholders' => true]);
        tarjim_use(Client::create($this->config(['bundle' => 'backend', 'keyCase' => 'lower', 'legacyPercentPlaceholders' => true])));
        tarjim_language('en');
    }

    protected function tearDown(): void
    {
        tarjim_use(null);
        parent::tearDown();
    }

    public function testTGivesSafeHtmlWithMappings(): void
    {
        self::assertSame('Hello <b>Ana &lt;3</b>', _T('greeting', ['mappings' => ['name' => 'Ana <3']]));
        self::assertSame('Hi there', _T('unsafe'));
    }

    public function testTsGivesPlainUnescapedText(): void
    {
        self::assertSame('Tom & Jerry\'s "page"', _TS('title'));
    }

    public function testKeysAreFoldedAndMissingKeysAnswerAsWritten(): void
    {
        self::assertSame('Tom & Jerry\'s "page"', _TS('TITLE'));
        self::assertSame('Missing Key', _TS('Missing Key'));
    }

    public function testTheCurrentLanguageIsPassedOnEveryCall(): void
    {
        tarjim_language('fr');
        self::assertSame('Bonjour <b>Ana</b>', _T('greeting', ['mappings' => ['name' => 'Ana']]));
        tarjim_language('en');
        self::assertSame('Hello <b>Ana</b>', _T('greeting', ['mappings' => ['name' => 'Ana']]));
    }

    public function testTheOldNamespaceOptionSelectsABundle(): void
    {
        self::assertSame('Hello from rccaq', _TS('greeting', ['namespace' => 'rccaq-backend']));
    }

    public function testTmGivesAnEscapedAttributeStringForAnImage(): void
    {
        tarjim_language('ar');
        $attributes = _TM('logo', ['alt' => 'ignored', 'title' => 'T & T']);

        self::assertStringContainsString('src="https://cdn.example.test/logo.png"', $attributes);
        self::assertStringContainsString('alt="Logo &quot;x&quot;"', $attributes);
        self::assertStringContainsString('title="T &amp; T"', $attributes);
        self::assertStringNotContainsString('onerror', $attributes);
    }

    public function testTmWithoutAnImageGivesNoSource(): void
    {
        self::assertStringNotContainsString('src=', _TM('missing.image'));
    }

    public function testTdGivesEveryLanguageAndEmptyWhereMissing(): void
    {
        $all = _TD('home_endpoint');
        ksort($all);

        self::assertSame(['ar' => '', 'en' => 'home', 'fr' => 'accueil'], $all);
    }

    public function testTseoGivesEscapedMetaTags(): void
    {
        $tags = _TSEO('page_open_graph', ['SEO' => 'open_graph']);

        self::assertStringContainsString('<meta property="og:title" content="Title &amp; more"', $tags);
        self::assertStringContainsString('content="&lt;desc&gt;"', $tags);
    }

    public function testWithNoCopyEveryHelperAnswersWithTheKey(): void
    {
        tarjim_use(Client::create(['dir' => $this->root . '/empty-store', 'keyCase' => 'lower']));

        self::assertSame('home_endpoint', _TS('home_endpoint'));
        self::assertSame('greeting', _T('greeting'));
        self::assertSame([], _TD('home_endpoint'));
        self::assertSame('', _TM('logo'));
    }
}
