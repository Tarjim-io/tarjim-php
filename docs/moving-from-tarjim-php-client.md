# Moving from `joylab/tarjim-php-client`

`tarjim/tarjim-php` replaces `joylab/tarjim-php-client` and `joylab/tarjim-laravel-client`. The old
client read a project's live content over Tarjim v1 and refreshed itself during page requests; this
package serves the release a stage points at, from a folder that you refresh when you choose.

## What changes for your app

| Before | Now |
|---|---|
| Live, unreleased content | The release promoted to the stage your key reads (`dev` or `prod`) |
| A page request could call Tarjim: on an empty cache, and when the cache was older than 15 minutes (1.1.x) or an hour (1.2.x) | Pages never call Tarjim; `refresh` runs from your deploy or cron |
| `die()` when the cache was empty and the fetch failed | Lookups answer with the key text; `refresh` exits non-zero |
| Cache files pre-created with `chmod 777`, no locking, inside the web root (marketing site) | One folder you choose, outside the web root, written atomically by the site's own user |
| Config: a PHP file that sets variables | A PHP file that returns an array |
| Project id + API key in the config | A distribution's delivery URL + its delivery key |
| Namespaces `default` + `additional_namespaces` | One flat bundle per old namespace; the old `namespace` option selects a bundle |
| Keys lower-cased before lookup | `'keyCase' => 'lower'` (the default is exact) |
| `%%name%%` filled from `mappings` | `'legacyPercentPlaceholders' => true`; `{name}` always works |
| `<span data-tid=…>` around `_T` values (not for missing keys or `skip_*` calls), for the v1 Chrome extension's in-place editing | Gone, with that extension feature. Nothing on Tarjim v3 reads `data-tid`. |
| `_T` removed `<script>` and little else | `_T` keeps only safe tags and attributes (see `tHtml()` in the README) |
| `_TM` printed `src=… data-tid=…` unquoted, server attributes unescaped | `_TM` prints quoted, escaped attributes; https images only |
| `_TF`, `_TDateTime`, write and admin methods | Removed (no callers). Management commands come in a later version. |

Behaviour you may notice:

- A `%%name%%` with no value in `mappings` is left as written (it was blanked).
- Placeholders are never filled in the key-text fallback.
- With `keyCase: 'lower'`, when two key names fold to the same name the **first** one wins (it was
  the last).
- A value of `"0"` is a value (it fell back to the key).
- A key missing in the chosen language answers with the key text, as before; there is still no
  per-key fallback to another language. But a language that is not released at all is now served
  in `fallbackLocale` when you set one (it was the key text).
- **`_TS` used to strip tags; now it returns the value as written.** A value that holds HTML shows
  its markup wherever `_TS` is printed: use `_T` for those keys, or strip them in Tarjim.
- **`mappings` values used to be inserted raw.** `_T` now escapes them, and keeps an `href` only if
  the final URL is https, mailto or tel: a mapped link that is relative or `http://` loses its
  `href`. Pass absolute https URLs. The `thtml-diff.php` check below fills placeholders with an https
  sample, so it cannot show this case.
- An empty key answers `''` (it was `null`).

## Steps

1. **In Tarjim**, create one **flat** custom bundle per old namespace (the console creates nested
   bundles by default, and the layout cannot be changed later), and a distribution that serves them.
   Copy its delivery URL and keys. Keep its releases without an upper app version: a PHP client
   sends none.
2. **Install** the package and remove the old ones:

   ```bash
   composer remove joylab/tarjim-php-client joylab/tarjim-laravel-client
   composer require tarjim/tarjim-php
   ```

3. **Config file**, returning an array. On the Laravel site, replace the old wrapper's
   `config/tarjim.php` (or use another name, such as `config/tarjim-delivery.php`); its old keys mean
   nothing here:

   ```php
   <?php
   return [
       'dir' => '/var/lib/myapp/tarjim',              // absolute, outside the web root
       'deliveryUrl' => getenv('TARJIM_DELIVERY_URL'),
       'key' => getenv('TARJIM_DELIVERY_KEY'),        // never commit it
       'bundle' => 'backend',                          // the old default namespace
       'keyCase' => 'lower',                           // the old client's behaviour
       'legacyPercentPlaceholders' => true,            // if your text uses %%name%%
       'fallbackLocale' => 'en',
   ];
   ```

4. **Copy the helper file** [`examples/tarjim-helpers.php`](../examples/tarjim-helpers.php) into your
   app and load it after Composer's autoloader (Laravel: add it to `autoload.files` in
   `composer.json`; CakePHP: below), then give it a client and set the language wherever the app
   switches it:

   ```php
   tarjim_use(Tarjim\Client::create(require __DIR__ . '/config/tarjim.php'));
   tarjim_language('fr');                    // a middleware; before rendering each email
   _TS('home_endpoint', ['locale' => 'ar']); // one call in another language (route slugs per locale)
   ```

   In a long-running worker (Octane, a queue), `tarjim_language(null)` at the end of each request
   or job, so the next one does not inherit it. With no client set the helpers answer with the key.

5. **Review the call sites the helpers change:**
   - **`_TS` now returns plain, unescaped text.** Each call site escapes once for where the text
     goes: `{{ _TS('page_title') }}` in Blade escapes already; use `htmlspecialchars()` in an
     attribute and `json_encode()` inside JavaScript; nothing in an SMS or an email subject. Escaping
     inside the helper would double-escape Blade and put `&#039;` in SMS.
   - **Move JavaScript strings, JSON responses and email subjects from `_T` to `_TS`.** `_T` returns
     HTML for a page body only (it used to wrap a `data-tid` span into JSON and emails too).
   - **`_TM`** prints `src="…"` plus quoted attributes for an `<img>`, or nothing when the key holds
     no https image: `<img <?= _TM('navbar_logo_image', ['alt' => 'logo']) ?>>`.
   - **`_TD`** returns `['en' => …, 'fr' => …]` with `''` where a language lacks the key, for
     reverse lookups of translated URL slugs. **With no copy held it returns `[]`** and `_TS` returns
     the key text, so routes built from them at boot (route slugs per language) are wrong until a
     copy exists: run `refresh --require-copy` before building routes or caching them.
   - **`_TSEO`** reads a key whose value is a JSON object and prints escaped `<meta>` tags.
6. **Refresh** in your deploy, as the user the site runs as, before routes are cached:

   ```bash
   vendor/bin/tarjim-php refresh --config=config/tarjim.php --require-copy
   ```

   and from cron, or a scheduler, every few minutes:

   ```php
   // Laravel: routes/console.php
   Illuminate\Support\Facades\Schedule::exec('vendor/bin/tarjim-php refresh --config=config/tarjim.php')->everyFiveMinutes();
   ```

   An old "update the cache" URL can call `tarjim_use()->refresh()` instead; it reaches Tarjim at
   most once a minute however often it is called.

   `refresh` checks that the configured `bundle` exists and is flat. A second bundle you read with
   `'namespace' => …` (Panda7's `rccaq-backend`) is not checked: create it flat, and check it once
   with `tarjim-php status` or a lookup after the first refresh.
7. **Check what `_T` will now strip.** Run, against a folder refreshed with your key:

   ```bash
   php vendor/tarjim/tarjim-php/scripts/thtml-diff.php --dir=/var/lib/myapp/tarjim --legacy
   ```

   It lists every value whose `_T` output a reader would see differently from the stored text
   (tab-separated: bundle, language, key, stored, output): a dropped tag, attribute, link or text.
   Differences only in how characters are written, such as `&` becoming `&amp;`, are not listed;
   `--all` lists them too. Fix the text in Tarjim, or accept the change.
8. **Remove** the old cache folder (on the marketing site it sits inside the web root:
   `public/tarjim/app`), the old `chmod 777` steps and the old config variables.

## Laravel

```php
// app/Providers/AppServiceProvider.php, in register()
$this->app->singleton(Tarjim\Client::class, function () {
    return Tarjim\Client::create(require base_path('config/tarjim.php'));
});
```

```php
// a middleware, after the locale is known
tarjim_use(app(Tarjim\Client::class));
tarjim_language(app()->getLocale());
```

Laravel's own `lang/` files are untouched by this package. To ship Tarjim text as Laravel language
files instead, use an export pull (`tarjim-php export`, see the README).

## CakePHP 1.3

```php
// app/config/bootstrap.php
require_once ROOT . DS . 'vendor' . DS . 'autoload.php';
require_once APP . 'vendors' . DS . 'tarjim-helpers.php';
tarjim_use(Tarjim\Client::create(require CONFIGS . 'tarjim.php'));
```

```php
// AppController::beforeFilter() and before each email is rendered
tarjim_language(Configure::read('Config.language'));
```

The old `cake tarjim updateTarjimLocale` shell becomes `vendor/bin/tarjim-php refresh` in the setup
scripts; keep `|| echo '[warn] …'` where a failed refresh must not stop the boot, or use
`--require-copy` where it must.
