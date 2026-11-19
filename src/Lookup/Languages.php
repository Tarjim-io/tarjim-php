<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

final class Languages
{
    /** `fr_CA`, `FR-ca` and `fr-CA` are one tag. */
    public static function normalise(string $tag): string
    {
        return strtolower(str_replace('_', '-', $tag));
    }

    /**
     * The first language of the chain the bundle has a file for: the exact tag, its base language,
     * then the same two for the fallback. That one is served whole, even where it lacks the key.
     *
     * @param array<string, string> $available tag as released => object hash
     * @return string|null the tag as released
     */
    public static function pick(array $available, ?string $locale, ?string $fallback): ?string
    {
        $byNormal = [];
        foreach ($available as $tag => $hash) {
            $normal = self::normalise((string) $tag);
            if (!isset($byNormal[$normal])) {
                $byNormal[$normal] = (string) $tag;
            }
        }
        foreach ([$locale, $fallback] as $wanted) {
            if ($wanted === null) {
                continue;
            }
            $normal = self::normalise($wanted);
            $dash = strpos($normal, '-');
            foreach ($dash === false ? [$normal] : [$normal, substr($normal, 0, $dash)] as $candidate) {
                if (isset($byNormal[$candidate])) {
                    return $byNormal[$candidate];
                }
            }
        }

        return null;
    }
}
