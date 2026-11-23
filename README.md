# tarjim/tarjim-php

Serve the text you release in [Tarjim](https://tarjim.io) from a PHP application.

The package keeps a verified copy of a release in a folder on each server, and your pages read text
from that copy. Pages never call Tarjim: the copy is refreshed only when you ask, from a deploy
step, a cron job or a command.

It can also pull a bundle export (for example Laravel PHP language files) into a folder.

**Alpha.** Versions are `-alpha.N` until a real site runs on the package, then `-beta.N` until
the release checklist in [RELEASING.md](RELEASING.md) is met.

## Requirements

- PHP 7.4 or later, with `ext-json` and `ext-curl`. No other Composer packages.
- `ext-dom` for `tHtml()`, `ext-mbstring` for `keyCase: 'lower'`, `ext-zip` for export pulls.
  `ext-intl` lets export pulls tell apart file names that differ only in Unicode form.

```bash
composer require tarjim/tarjim-php
```

## Quick start

In Tarjim, create a distribution for your site and copy its delivery URL and its keys: the
Development key reads the `dev` stage, the Production key the `prod` stage.

```php
use Tarjim\Client;

$tarjim = Client::create([
    'dir' => '/var/lib/myapp/tarjim',          // absolute; outside the web root
    'deliveryUrl' => getenv('TARJIM_DELIVERY_URL'),
    'key' => getenv('TARJIM_DELIVERY_KEY'),
    'locale' => 'fr',
    'fallbackLocale' => 'en',
]);

echo $tarjim->tHtml('welcome_title');                 // safe HTML
echo htmlspecialchars($tarjim->t('page_title'));       // plain text: escape it yourself
```

Fill the folder before the site serves pages, and keep it fresh:

```bash
# in the deploy, as the user the site runs as
vendor/bin/tarjim-php refresh --config=config/tarjim.php --require-copy

# from cron, every few minutes
*/5 * * * * cd /var/www/myapp && vendor/bin/tarjim-php refresh --config=config/tarjim.php
```

`config/tarjim.php` returns the same array you pass to `Client::create()`.

## The local copy

- **One folder per server**, outside the web root, holding one distribution. Two servers may differ
  for a few minutes after a promote. In containers the folder lives as long as the container: run
  `refresh` when the container starts, or during the image build into the same folder.
- **Run `refresh` as the user the site runs as** (for php-fpm usually `www-data`). A copy written by
  root cannot be updated by the web server; `refresh` checks the folder is writable first and
  answers `misconfigured` if not. Under php-fpm, `getenv()` sees the environment only with
  `clear_env = no`; otherwise put the values in the config file from your own secret store.
- **Atomic.** A release is installed only when every file is verified, and the pointer to it is
  written last. A reader sees the old release or the new one, never a mix. A failed refresh never
  removes what is held.
- **At most one check a minute** per folder, across processes; `Retry-After` is honoured.
- **The key is never written** to the folder, a log line or a message.
- The current and previous release are kept; anything else is removed a day after it stopped being
  used.
- A long-running process (a queue worker, Octane) notices a promote within a second of the next
  lookup.

### `refresh` exit codes

| | exit |
|---|---|
| updated, unchanged, not due yet, another refresh running, nothing released yet | 0 |
| failed (network, server, a file that does not verify) | 1 |
| misconfigured (bad config, folder not writable, key refused, configured bundle missing or not flat) | 2 |
| `--wait=<seconds>` ran out while waiting for the lock, the one-minute floor, `Retry-After` or a first release | 3 |
| `--require-copy` and no usable copy held afterwards | 4 |

With `--require-copy`, the exit is 0 whenever a usable copy is held after the run, whatever the
run's outcome (a failure is still printed). Use it in a deploy that must not go live without text.

`vendor/bin/tarjim-php status --config=…` never calls Tarjim: 0 a fresh copy, 1 a stale one (no
successful check within `staleAfter`), 2 bad config, 4 nothing held.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `dir` | — | Absolute folder for the copy. Needed to read and to refresh. |
| `deliveryUrl` | — | The distribution's delivery URL, `https://…/delivery/<id>/meta`, used exactly as given: https (http only on localhost), no query, fragment or trailing slash. |
| `key` | — | The delivery key. Needed only to refresh. |
| `bundle` | `'default'` | The bundle lookups read. A custom bundle of that name first, then a namespace. It must be a **flat** bundle. |
| `locale` | — | The language lookups serve. With no `locale`, no `fallbackLocale` and no per-call locale, lookups answer with the key text. |
| `fallbackLocale` | — | Served when `locale` has no text in the bundle. |
| `keyCase` | `'exact'` | `'lower'` folds key names on both sides. |
| `legacyPercentPlaceholders` | `false` | Also fill `%%name%%`, as the old client did. |
| `staleAfter` | `86400` | Seconds without a successful check before `status` reports stale. |
| `logger` | none | `function (string $level, string $message, array $context)`. |
| `caBundle` | system | A CA file for TLS. |
| `strict` | `false` | Throw on a bad config instead of degrading. |
| `apiUrl`, `apiKey` | — | For export pulls only. |

`Client::create()` never throws (unless `strict`). A bad `dir`, `deliveryUrl` or `key` makes
`refresh()` answer `misconfigured`; a bad optional value falls back to its default. Both are logged,
and lookups answer with the key text.

## Lookups

Lookups read only the local copy: they never call Tarjim, never write and never throw.

```php
$tarjim->t('cart.empty');                              // text as written
$tarjim->t('cart.count', ['count' => 3]);              // fills {count}
$tarjim->tHtml('terms.intro', ['name' => $userName]);  // safe HTML; inserted values escaped
$tarjim->has('promo.banner');                          // true only for a non-empty value
$tarjim->image('home.hero');                           // ?Image: url (https only) and attributes
$tarjim->locales();                                    // the languages the bundle has
$tarjim->t('cart.empty', [], ['locale' => 'ar', 'bundle' => 'emails']);
```

- **A missing key, or an empty value, answers with the key text**, unfilled.
- **Language:** the exact tag, then a less specific or sibling form of the same language and script
  (as the JavaScript SDK chooses), then `fallbackLocale`. `zh-TW` is served `zh-Hant`, never
  `zh-Hans`. The chosen language is served whole: a key it lacks answers with the key text, not
  another language's text. `fr_CA` and `fr-CA` are the same tag.
- **Placeholders:** `{name}` from a named array, only when arguments are given. Inserted text is
  never scanned again. Strings, numbers and objects with `__toString()` are inserted; anything else
  leaves the placeholder as written.
- **`tHtml()`** keeps `a[href]` (https, mailto, tel), `b strong i em u br p span ul ol li h1–h6 hr
  div table thead tbody tr th td` and `img[src]` (https) `[alt]`, plus `class` on any of them and
  `target="_blank"` on a link (always with `rel="noopener noreferrer"`). Every other tag and
  attribute is removed, `style` included; `script`, `style`, `iframe`, `svg` and similar are removed
  with their content. Use its
  output in an HTML body only; for attributes, JavaScript, JSON, email subjects and SMS use `t()`
  and escape for that context.
- **`image()`** returns an image only for an `https` URL, with `alt`, `title`, `width`, `height`
  and `loading` at most.
- **Staleness is not logged by lookups.** A site that refreshes only at deploy is "stale" a day
  later by design; `status()` and `tarjim-php status` report it.

```php
$status = $tarjim->status();
if ($status->stale) {
    // alert: $status->lastSuccessAt, $status->releaseId
}
```

## Export pulls

Pull a bundle export (any format, including Laravel PHP files) into a folder:

```php
$tarjim = Client::create(['apiUrl' => 'https://api.tarjim.io', 'apiKey' => getenv('TARJIM_API_KEY')]);
$result = $tarjim->exports()->pull(12, 34, '/var/www/myapp/lang');   // project 12, bundle 34
// $result->status: 'updated' | 'failed' | 'misconfigured'; $result->written, $result->removed
```

```bash
vendor/bin/tarjim-php export --config=config/tarjim.php --project=12 --bundle=34 --out=/var/www/myapp/lang
```

- **It owns the files it wrote, not the folder.** It records them in `.tarjim-export.json` and the
  next pull removes only those the new export no longer has. Files it never wrote are left alone,
  and a pull that would overwrite one fails, naming it; pass `['adoptExisting' => true]` to take
  such files over (for example files the old client wrote).
- **All or nothing:** a failed pull leaves the folder as it was.
- A zip entry that could land outside the folder, a link, an encrypted entry or an export larger
  than `maxBytes` (64 MiB, unpacked) refuses the whole export.
- Options: `timeout` (300 s), `poll` (2 s), `maxBytes`, `adoptExisting`.
- `tarjim-php export` exits 0 when files were written, 1 when the pull failed, 2 on a bad config or
  bad arguments.

## Moving from `joylab/tarjim-php-client`

See [docs/moving-from-tarjim-php-client.md](docs/moving-from-tarjim-php-client.md), with a helper
file that keeps `_T`, `_TS`, `_TM`, `_TD` and `_TSEO` working: [examples/tarjim-helpers.php](examples/tarjim-helpers.php).

## Development

```bash
composer update
vendor/bin/phpunit          # unit tests and real-curl tests against a local php -S server
vendor/bin/phpstan analyse
```

Tests never call a Tarjim server.

## Licence

MIT
