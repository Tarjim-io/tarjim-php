<?php

declare(strict_types=1);

// Checks a real stage end to end before a release: reads its meta, installs its release into a
// temporary folder, reads the copy back, and removes the folder. Never run by tests or CI.
//
//   read -rs TARJIM_KEY && export TARJIM_KEY
//   php scripts/staging-check.php --delivery-url=https://…/meta [--bundle=<name>] [--record=/abs/dir]
//
// The key is read from TARJIM_KEY only, so it never lands in shell history or a process list.
// --record writes the stage's answers as fixtures with every key name, value and signature replaced.
// Exit: 0 the release installed and reads back, 1 something failed, 2 bad arguments.

use Tarjim\Client;
use Tarjim\Html\SafeHtml;
use Tarjim\Lookup\BundleChoice;
use Tarjim\RefreshResult;
use Tarjim\Store\LocalDisk;
use Tarjim\Store\Store;
use Tarjim\Wire\CurlTransport;
use Tarjim\Wire\DeliveryClient;
use Tarjim\Wire\DeliveryUrl;
use Tarjim\Wire\Meta;
use Tarjim\Wire\Outcome;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['delivery-url:', 'bundle:', 'record:', 'key:']);
$options = is_array($options) ? $options : [];
if (isset($options['key'])) {
    fwrite(STDERR, "never pass the key on the command line: export TARJIM_KEY instead\n");
    exit(2);
}
$url = is_string($options['delivery-url'] ?? null) ? $options['delivery-url'] : '';
$problem = DeliveryUrl::problem($url);
if ($problem !== null) {
    fwrite(STDERR, "--delivery-url: {$problem}\n");
    exit(2);
}
$key = getenv('TARJIM_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, "set TARJIM_KEY to the stage's delivery key (read -rs TARJIM_KEY && export TARJIM_KEY)\n");
    exit(2);
}
$bundle = is_string($options['bundle'] ?? null) ? $options['bundle'] : 'default';
$record = is_string($options['record'] ?? null) ? $options['record'] : null;
if ($record !== null && ($record === '' || $record[0] !== '/')) {
    fwrite(STDERR, "--record must be an absolute folder\n");
    exit(2);
}

$say = static function (string $line) use ($key): void {
    echo str_replace($key, '[key]', $line), "\n";
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

$dir = sys_get_temp_dir() . '/tarjim-staging-check-' . bin2hex(random_bytes(6));
$ok = true;
try {
    $wire = new DeliveryClient(new CurlTransport(), $url, $key);
    $answer = $wire->fetchMeta();
    if ($answer->kind !== Outcome::RECEIVED || $answer->meta === null) {
        $say('meta: ' . $answer->kind . ($answer->status !== null ? ' (HTTP ' . $answer->status . ')' : '') . ($answer->code !== null ? ' ' . $answer->code : ''));
        $ok = false;
    } else {
        $meta = $answer->meta;
        $say('serving mode: ' . ($meta->authenticated ? 'origin' : 'CDN'));
        $say("release {$meta->releaseId} of {$meta->distribution} ({$meta->stage})");

        $client = Client::create(['dir' => $dir, 'deliveryUrl' => $url, 'key' => $key, 'bundle' => $bundle]);
        $result = $client->refresh();
        $say('refresh: ' . $result->status . ($result->message !== '' ? ' — ' . $result->message : ''));
        if ($result->status !== RefreshResult::UPDATED || !$result->copyHeld) {
            $ok = false;
        }
        $status = $client->status();
        if ($status->releaseId !== $meta->releaseId) {
            $say('the copy holds release ' . var_export($status->releaseId, true) . ', not ' . $meta->releaseId);
            $ok = false;
        }

        $locales = $client->locales();
        sort($locales);
        $say("bundle {$bundle}: " . ($locales === [] ? 'no languages' : implode(', ', $locales)));
        $ok = $ok && $locales !== [];

        // Every value read back through the client, and what tHtml() would change.
        $store = new Store(new LocalDisk(), $dir);
        $index = $store->currentIndex();
        $chosen = $index === null ? null : BundleChoice::find($index['bundles'], $bundle);
        $files = $chosen === null ? [] : $index['bundles'][$chosen]['languages'];
        foreach ($files as $language => $hash) {
            $slice = json_decode((string) $store->readObject($hash), true);
            $keys = is_array($slice) && is_array($slice['keys'] ?? null) ? $slice['keys'] : [];
            $values = 0;
            $changed = 0;
            $readBack = true;
            foreach ($keys as $name => $leaf) {
                $value = is_array($leaf) ? ($leaf['value'] ?? null) : null;
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $values++;
                if ($client->t((string) $name, [], ['locale' => (string) $language]) !== $value) {
                    $readBack = false;
                }
                if ((!isset($leaf['type']) || $leaf['type'] === 'text') && SafeHtml::render($value, [], false) !== $value) {
                    $changed++;
                }
            }
            $say("  {$language}: {$values} values, " . ($readBack ? 'all read back' : 'SOME DID NOT READ BACK') . ", {$changed} changed by tHtml()");
            $ok = $ok && $readBack && $values > 0;
        }

        if ($record !== null && $index !== null) {
            $written = recordFixtures($wire, $meta, $store, $index, $record);
            $say("recorded {$written} fixture files in {$record}; read them before committing");
        }
    }
} catch (\Throwable $error) {
    $say('failed: ' . get_class($error));
    $ok = false;
} finally {
    $removeTree($dir);
}

exit($ok ? 0 : 1);

/**
 * The stage's meta, manifest and one slice per bundle and language, with nothing a public
 * repository must not hold: signature values, key names and values are replaced. The slice hashes
 * then no longer match; the fixtures are for shape, not for verification.
 *
 * @param array{name: string, bundles: array<string, array{type: string, name: string, layout: string|null, languages: array<string, string>}>} $index
 */
function recordFixtures(DeliveryClient $wire, Meta $meta, Store $store, array $index, string $out): int
{
    if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
        throw new RuntimeException('cannot create the record folder');
    }
    $count = 0;
    $fields = get_object_vars($meta);
    $fields['signedQuery'] = $meta->signedQuery === null ? null : (string) preg_replace('/=([^&]*)/', '=REDACTED', $meta->signedQuery);
    unset($fields['etag']);
    file_put_contents($out . '/meta.json', json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $count++;

    $manifest = $wire->fetchManifest($meta);
    if ($manifest->bytes !== null) {
        file_put_contents($out . '/manifest.json', $manifest->bytes);
        $count++;
    }

    foreach ($index['bundles'] as $id => $bundle) {
        foreach ($bundle['languages'] as $language => $hash) {
            $slice = json_decode((string) $store->readObject($hash), true);
            if (!is_array($slice) || !is_array($slice['keys'] ?? null)) {
                continue;
            }
            $keys = [];
            $n = 0;
            foreach ($slice['keys'] as $leaf) {
                $n++;
                $leaf = is_array($leaf) ? $leaf : [];
                $clean = ['id' => (string) $n, 'value' => 'value-' . $n];
                if (isset($leaf['type']) && is_string($leaf['type'])) {
                    $clean['type'] = $leaf['type'];
                    if ($leaf['type'] === 'image') {
                        $clean['value'] = 'https://cdn.example.test/image-' . $n . '.png';
                    }
                }
                if (is_array($leaf['attributes'] ?? null)) {
                    $clean['attributes'] = array_map(static function (): string {
                        return 'attribute';
                    }, $leaf['attributes']);
                }
                $keys['key-' . $n] = $clean;
            }
            $slice['keys'] = $keys;
            $name = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $id . '-' . $language);
            file_put_contents($out . '/slice-' . $name . '.json', json_encode($slice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $count++;
        }
    }

    return $count;
}
