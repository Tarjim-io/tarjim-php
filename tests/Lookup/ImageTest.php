<?php

declare(strict_types=1);

namespace Tarjim\Tests\Lookup;

use Tarjim\Tests\Support\ReleaseBuilder;

final class ImageTest extends LookupCase
{
    private function fixture(): ReleaseBuilder
    {
        return self::leaves(new ReleaseBuilder('7'), 'ns1', ['type' => 'namespace', 'name' => 'default', 'layout' => 'flat'], 'en', [
            'logo' => ['id' => '1', 'type' => 'image', 'value' => 'https://cdn.example.test/logo.png', 'attributes' => [
                'alt' => 'Logo', 'title' => 'Our logo', 'width' => '120', 'height' => '40', 'loading' => 'lazy',
                'onerror' => 'alert(1)', 'style' => 'x', 'class' => 'big', 'src' => 'https://evil.example.test/x.png', 'id' => 'a',
            ]],
            'numeric.attrs' => ['id' => '2', 'type' => 'image', 'value' => 'https://cdn.example.test/a.png', 'attributes' => ['width' => 120, 'alt' => ['x']]],
            'plain.http' => ['id' => '3', 'type' => 'image', 'value' => 'http://cdn.example.test/logo.png'],
            'script' => ['id' => '4', 'type' => 'image', 'value' => 'javascript:alert(1)'],
            'data' => ['id' => '5', 'type' => 'image', 'value' => 'data:image/png;base64,AAAA'],
            'upper' => ['id' => '6', 'type' => 'image', 'value' => 'HTTPS://cdn.example.test/logo.png'],
            'relative' => ['id' => '7', 'type' => 'image', 'value' => '//cdn.example.test/logo.png'],
            'text' => ['id' => '8', 'value' => 'https://cdn.example.test/not-an-image.png'],
            'video' => ['id' => '9', 'type' => 'audio/video', 'value' => 'https://cdn.example.test/v.mp4'],
            'spaced' => ['id' => '10', 'type' => 'image', 'value' => " https://cdn.example.test/logo.png"],
        ]);
    }

    public function testAnImageComesWithItsAllowedAttributesOnly(): void
    {
        $image = $this->installed($this->fixture(), ['locale' => 'en'])->image('logo');

        self::assertNotNull($image);
        self::assertSame('https://cdn.example.test/logo.png', $image->url);
        $attributes = $image->attributes;
        ksort($attributes);
        self::assertSame(['alt' => 'Logo', 'height' => '40', 'loading' => 'lazy', 'title' => 'Our logo', 'width' => '120'], $attributes);
    }

    public function testAttributesThatAreNotTextAreDropped(): void
    {
        $image = $this->installed($this->fixture(), ['locale' => 'en'])->image('numeric.attrs');

        self::assertNotNull($image);
        self::assertSame([], $image->attributes);
    }

    /** @return iterable<string, array{string}> */
    public function notImages(): iterable
    {
        yield 'plain http' => ['plain.http'];
        yield 'javascript' => ['script'];
        yield 'data url' => ['data'];
        yield 'protocol relative' => ['relative'];
        yield 'a text value' => ['text'];
        yield 'a video' => ['video'];
        yield 'leading space' => ['spaced'];
        yield 'missing' => ['missing'];
    }

    /** @dataProvider notImages */
    public function testOnlyAnHttpsImageIsAnImage(string $key): void
    {
        self::assertNull($this->installed($this->fixture(), ['locale' => 'en'])->image($key));
    }

    public function testAnUpperCaseSchemeIsStillHttps(): void
    {
        $image = $this->installed($this->fixture(), ['locale' => 'en'])->image('upper');

        self::assertNotNull($image);
    }

    public function testNoImageWithoutACopy(): void
    {
        self::assertNull($this->client(['locale' => 'en'])->image('logo'));
    }
}
