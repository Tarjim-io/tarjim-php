<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\Client;
use Tarjim\RefreshResult;
use Tarjim\Tests\Support\ReleaseBuilder;
use Tarjim\Tests\Support\StoreCase;

abstract class LookupCase extends StoreCase
{
    /** @param array<string, mixed> $leaves name => leaf */
    protected static function leaves(ReleaseBuilder $release, string $bundleId, array $bundle, string $locale, array $leaves): ReleaseBuilder
    {
        return $release->slice($bundleId, $bundle + ['namespaces' => []], $locale, [
            'schemaVersion' => 1,
            'bundle' => $bundleId,
            'locale' => $locale,
            'keys' => $leaves,
        ]);
    }

    /** Installs $release and returns a client reading it. */
    protected function installed(ReleaseBuilder $release, array $overrides = []): Client
    {
        $this->serve($release);
        $result = $this->refresh([], $overrides);
        self::assertSame(RefreshResult::UPDATED, $result->status, $result->message);
        $this->fake->requests = [];

        return $this->client($overrides);
    }

    /** Default namespace bundle in English, French, Canadian French and Arabic. */
    protected function languages(): ReleaseBuilder
    {
        return (new ReleaseBuilder('7'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello', 'only.en' => 'English only', 'empty.fr' => 'Not empty'])
            ->flat('ns1', 'namespace', 'default', 'fr', ['hello' => 'Bonjour', 'empty.fr' => ''])
            ->flat('ns1', 'namespace', 'default', 'fr-CA', ['hello' => 'Allô'])
            ->flat('ns1', 'namespace', 'default', 'ar', ['hello' => 'مرحبا']);
    }
}
