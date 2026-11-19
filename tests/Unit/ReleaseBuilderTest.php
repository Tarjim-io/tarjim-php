<?php

declare(strict_types=1);

namespace Tarjim\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tarjim\Tests\Support\Fixtures;
use Tarjim\Tests\Support\ReleaseBuilder;

final class ReleaseBuilderTest extends TestCase
{
    public function testHashesAndSizesAgreeWithTheBytes(): void
    {
        $release = (new ReleaseBuilder('9'))
            ->flat('ns1', 'namespace', 'default', 'en', ['hello' => 'Hello'])
            ->flat('ns1', 'namespace', 'default', 'ar', ['hello' => 'مرحبا']);
        $manifest = json_decode($release->manifestBytes(), true);

        self::assertSame(hash('sha256', $release->manifestBytes()), $release->meta()['checksum']);
        foreach ($manifest['slices']['ns1'] as $file) {
            $bytes = $release->files[$file['json']['hash']];
            self::assertSame($file['json']['hash'], hash('sha256', $bytes));
            self::assertSame($file['json']['size'], strlen($bytes));
        }
    }

    public function testFixturesLoadAndHoldNoRealSignature(): void
    {
        $meta = Fixtures::json('meta-cdn.json');
        self::assertFalse($meta['authenticated']);
        self::assertStringContainsString('not-a-real-signature', $meta['signedQuery']);
    }
}
