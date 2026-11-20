<?php

declare(strict_types=1);

namespace Tarjim\Lookup;

final class Languages
{
    // Extensions (`-u-ca-gregory`) and private use (`-x-…`) are accepted and dropped, as Intl.Locale's
    // baseName drops them, so both SDKs read such a tag as the same language.
    private const TAG = '/^([a-z]{2,3}|[a-z]{5,8})(?:-([a-z]{4}))?(?:-([a-z]{2}|[0-9]{3}))?((?:-(?:[a-z0-9]{5,8}|[0-9][a-z0-9]{3}))*)(?:-[0-9a-wy-z](?:-[a-z0-9]{2,8})+)*(?:-x(?:-[a-z0-9]{1,8})+)?$/D';

    private const PARSED_LIMIT = 512;

    /** @var array<string, array{base: string, language: string, script: string}|null> */
    private static array $parsed = [];

    /** @var array<string, string>|null */
    private static ?array $defaultScript = null;

    /** `fr_CA`, `FR-ca` and `fr-CA` are one tag. */
    public static function normalise(string $tag): string
    {
        // Not strtolower(): before PHP 8.2 it follows the process locale, and setlocale() in the app would change matching.
        return strtr(str_replace('_', '-', $tag), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * The entry of `$available` that serves `$wanted`, spelled as `$available` spells it, or null. Exact tag,
     * then a less specific form of it, then a sibling: `$fallback` if it is one, else the sorted rule.
     * The same rule as the JS core's matchLocale.
     *
     * @param list<string> $available
     */
    public static function match(string $wanted, array $available, ?string $fallback = null): ?string
    {
        $target = self::parse($wanted);
        if ($target === null) {
            return null;
        }

        $candidates = [];
        foreach ($available as $tag) {
            $tag = (string) $tag;
            $parsed = self::parse($tag);
            if ($parsed !== null && $parsed['language'] === $target['language'] && $parsed['script'] === $target['script']) {
                $candidates[] = ['tag' => $tag, 'base' => $parsed['base'], 'parts' => substr_count($parsed['base'], '-') + 1, 'order' => str_replace('_', '-', $tag)];
            }
        }
        // The exact tag first, then the least specific one; sorted order settles what is left.
        usort($candidates, static function (array $a, array $b) use ($target): int {
            return [$a['base'] === $target['base'] ? 0 : 1, $a['parts'], $a['order']]
                <=> [$b['base'] === $target['base'] ? 0 : 1, $b['parts'], $b['order']];
        });
        if ($candidates === []) {
            return null;
        }
        $best = $candidates[0];
        if ($best['base'] === $target['base']) {
            return $best['tag'];
        }
        foreach ($candidates as $candidate) {
            if (strncmp($target['base'], $candidate['base'] . '-', strlen($candidate['base']) + 1) === 0) {
                return $best['tag'];
            }
        }
        if ($fallback !== null) {
            $key = self::key($fallback);
            foreach ($candidates as $candidate) {
                if ($candidate['base'] === $key) {
                    return $candidate['tag'];
                }
            }
        }

        return $best['tag'];
    }

    /**
     * The language of the bundle that serves the visitor: the wanted tag's match, else the fallback's.
     * That one is served whole, even where it lacks the key.
     *
     * @param array<string, string> $available tag as released => object hash
     * @return string|null the tag as released
     */
    public static function pick(array $available, ?string $locale, ?string $fallback): ?string
    {
        $tags = array_map('strval', array_keys($available));
        $tag = $locale === null ? null : self::match($locale, $tags, $fallback);

        return $tag ?? ($fallback === null ? null : self::match($fallback, $tags));
    }

    /** What two spellings of one tag share; a tag that is not well formed only loses its case. */
    private static function key(string $tag): string
    {
        $parsed = self::parse($tag);

        return $parsed === null ? self::normalise($tag) : $parsed['base'];
    }

    /**
     * Reads a tag the way Intl.Locale does, without ext-intl: `base` is the canonical language, script, region
     * and variants the tag spells (the likely script is not added), `script` the one it writes in.
     * Extensions are not read; a tag carrying one is not a tag here.
     *
     * @return array{base: string, language: string, script: string}|null
     */
    private static function parse(string $tag): ?array
    {
        if (array_key_exists($tag, self::$parsed)) {
            return self::$parsed[$tag];
        }
        if (count(self::$parsed) >= self::PARSED_LIMIT) {
            self::$parsed = [];
        }

        return self::$parsed[$tag] = self::read(self::normalise($tag));
    }

    /** @return array{base: string, language: string, script: string}|null */
    private static function read(string $tag): ?array
    {
        if (preg_match(self::TAG, $tag, $m) !== 1) {
            return null;
        }
        $language = $m[1];
        $script = $m[2];
        $region = $m[3];
        $variants = $m[4] === '' ? [] : explode('-', substr($m[4], 1));
        if (count($variants) !== count(array_unique($variants))) {
            return null;
        }
        sort($variants, SORT_STRING);

        // A canonical form may bring a script or region of its own (`sh` is `sr-Latn`); the tag's own win.
        if (isset(LikelyScripts::LANGUAGE_ALIASES[$language])) {
            $parts = explode('-', LikelyScripts::LANGUAGE_ALIASES[$language]);
            $language = $parts[0];
            foreach (array_slice($parts, 1) as $part) {
                if (strlen($part) === 4) {
                    $script = $script === '' ? $part : $script;
                } else {
                    $region = $region === '' ? $part : $region;
                }
            }
        }
        // Which region a deprecated one becomes can depend on the language (az-SU is az-AZ, ru-SU is ru-RU).
        $pair = self::pairAlias($language, $region);
        if ($pair !== null) {
            [$language, $region] = explode('-', $pair . '-');
        } else {
            $region = LikelyScripts::REGION_ALIASES[$region] ?? $region;
        }
        $script = LikelyScripts::SCRIPT_ALIASES[$script] ?? $script;

        return [
            'base' => implode('-', array_filter(array_merge([$language, $script, $region], $variants), static fn (string $part): bool => $part !== '')),
            'language' => $language,
            'script' => $script !== '' ? $script : self::likelyScript($language, $region),
        ];
    }

    private static function pairAlias(string $language, string $region): ?string
    {
        foreach (LikelyScripts::PAIR_ALIASES[$language] ?? [] as $target => $regions) {
            if ($region !== '' && strpos(' ' . $regions . ' ', ' ' . $region . ' ') !== false) {
                return (string) $target;
            }
        }

        return null;
    }

    /** '' for a language CLDR knows nothing of: only tags that are also unknown are then of one script. */
    private static function likelyScript(string $language, string $region): string
    {
        if (self::$defaultScript === null) {
            self::$defaultScript = [];
            foreach (LikelyScripts::DEFAULT_SCRIPTS as $script => $languages) {
                foreach (explode(' ', $languages) as $known) {
                    self::$defaultScript[$known] = (string) $script;
                }
            }
        }
        if (!isset(self::$defaultScript[$language])) {
            return '';
        }
        if ($region !== '') {
            foreach (LikelyScripts::REGION_SCRIPTS[$language] ?? [] as $script => $regions) {
                if (strpos(' ' . $regions . ' ', ' ' . $region . ' ') !== false) {
                    return (string) $script;
                }
            }
        }

        return self::$defaultScript[$language];
    }
}
