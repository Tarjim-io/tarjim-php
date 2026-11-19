<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

/** Which bundle a name selects: a custom bundle of that name first, then a namespace bundle. */
final class BundleChoice
{
    /** Only an absent layout or exactly `flat` is readable; any other value, 'Flat' included, is not. */
    public static function isFlat(?string $layout): bool
    {
        return $layout === null || $layout === 'flat';
    }

    /**
     * @param array<array-key, array{type: string, name: string}> $bundles by bundle id
     * @return string|null the id of the first match
     */
    public static function find(array $bundles, string $name): ?string
    {
        foreach (['custom', 'namespace'] as $type) {
            foreach ($bundles as $id => $bundle) {
                if ($bundle['type'] === $type && $bundle['name'] === $name) {
                    return (string) $id;
                }
            }
        }

        return null;
    }
}
