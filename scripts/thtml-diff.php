<?php

declare(strict_types=1);

// Lists every released value whose tHtml() output a reader would see differently from the stored
// text, so the change can be reviewed before an app moves to this package. Reads a local copy only:
// run `refresh` into the directory first.
//
//   php scripts/thtml-diff.php --dir=/absolute/store/dir [--legacy] [--bundle=<name>] [--all]
//
// The two sides are compared after decoding HTML entities, so a bare `&` becoming `&amp;` or `&#39;`
// becoming `'` is not listed; --all lists every textual difference instead.
// Output: one tab-separated line per changed value (bundle, language, key, stored, tHtml output),
// with tabs and line breaks shown as \t and \n. A summary goes to stderr.
// Exit: 0 nothing changes, 1 some values change, 2 usage or an unreadable copy.
//
// Each placeholder is filled with a plain https URL on both sides of the comparison, so a value
// like <a href="%%link%%"> is judged as it renders at runtime rather than reported as a dropped link.

use Tarjim\Html\SafeHtml;
use Tarjim\Lookup\BundleChoice;
use Tarjim\Lookup\Placeholders;
use Tarjim\Store\LocalDisk;
use Tarjim\Store\Store;

// From a checkout of this package, or from vendor/tarjim/tarjim-php/scripts in an app.
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$options = getopt('', ['dir:', 'legacy', 'bundle:', 'all']);
$dir = is_array($options) && isset($options['dir']) && is_string($options['dir']) ? $options['dir'] : '';
if ($dir === '' || $dir[0] !== '/') {
    fwrite(STDERR, "usage: php scripts/thtml-diff.php --dir=/absolute/store/dir [--legacy] [--bundle=<name>] [--all]\n");
    exit(2);
}
$legacy = isset($options['legacy']);
$all = isset($options['all']);
$only = isset($options['bundle']) && is_string($options['bundle']) ? $options['bundle'] : null;

$store = new Store(new LocalDisk(), rtrim($dir, '/'));
$index = $store->currentIndex();
if ($index === null) {
    fwrite(STDERR, "no usable copy in {$dir}: run `vendor/bin/tarjim-php refresh` into it first\n");
    exit(2);
}

// What a reader sees, roughly as a browser reads it: entities decoded, except that a `<` meant as
// text (escaped, or not followed by a letter, `/`, `!` or `?`) stays distinct from one that starts a
// tag. Otherwise stored `x<y>z`, shown as "xz", would compare equal to tHtml()'s "x&lt;y&gt;z".
$seen = static function (string $html): string {
    $html = (string) preg_replace('/&(?:lt|#0*60|#x0*3c);|<(?![A-Za-z\/!?])/i', "\u{E000}", $html);

    return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$show = static function (string $text): string {
    return strtr($text, ["\\" => "\\\\", "\t" => '\t', "\n" => '\n', "\r" => '\r']);
};

$checked = 0;
$changed = 0;
foreach ($index['bundles'] as $bundle) {
    if (!BundleChoice::isFlat($bundle['layout']) || ($only !== null && $bundle['name'] !== $only)) {
        continue;
    }
    foreach ($bundle['languages'] as $language => $hash) {
        $bytes = $store->readObject($hash);
        $slice = $bytes === null ? null : json_decode($bytes, true);
        $keys = is_array($slice) && is_array($slice['keys'] ?? null) ? $slice['keys'] : null;
        if ($keys === null) {
            fwrite(STDERR, "cannot read {$bundle['name']} / {$language}; skipped\n");
            continue;
        }
        foreach ($keys as $key => $leaf) {
            $value = is_array($leaf) ? ($leaf['value'] ?? null) : null;
            if (!is_string($value) || $value === '' || (isset($leaf['type']) && $leaf['type'] !== 'text')) {
                continue;
            }
            $checked++;
            $sample = [];
            if (preg_match_all('/\{([^{}]+)\}|%%([^%\s]+)%%/', $value, $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $match) {
                    $sample[$match[1] !== '' ? $match[1] : $match[2]] = 'https://example.test/sample';
                }
            }
            $expected = $sample === [] ? $value : Placeholders::fill($value, $sample, $legacy);
            $rendered = SafeHtml::render($value, $sample, $legacy);
            if ($all ? $rendered !== $expected : $seen($rendered) !== $seen($expected)) {
                $changed++;
                echo implode("\t", [$show($bundle['name']), $show((string) $language), $show((string) $key), $show($value), $show($rendered)]), "\n";
            }
        }
    }
}

fwrite(STDERR, "{$checked} values checked, {$changed} " . ($all ? 'differ as text' : 'change what a reader sees') . "\n");
exit($changed === 0 ? 0 : 1);
