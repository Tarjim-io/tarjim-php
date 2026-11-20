<?php

// The old client's global helpers, mapped onto tarjim/tarjim-php. Copy this file into your app, load
// it once after Composer's autoloader (Composer "files" autoload, or require it at boot), and call
// tarjim_use() with your client.
//
// The client has no "current language" of its own: set it with tarjim_language() wherever the app
// switches language (a middleware, before rendering each email), and reset it at the end of a
// request in a long-running worker. A helper also takes `'locale' => 'fr'` for a single call.
//
// Like the client's lookups, the helpers never throw: with no client set they answer with the key.

use Tarjim\Client;

/** The client the helpers use; pass null to forget it. */
function tarjim_use(?Client $client = null): ?Client
{
    static $current = null;
    if (func_num_args() > 0) {
        $current = $client;
    }

    return $current;
}

/** The language the helpers ask for; pass a tag to change it, or null to clear it. */
function tarjim_language(?string $set = null): ?string
{
    static $language = null;
    if (func_num_args() > 0) {
        $language = $set;
    }

    return $language;
}

/**
 * Lookup options from the old `namespace` option, a per-call `locale`, or the current language.
 *
 * @param mixed $config
 * @return array<string, string>
 */
function tarjim_options($config): array
{
    $config = is_array($config) ? $config : [];
    $options = [];
    $locale = is_string($config['locale'] ?? null) ? $config['locale'] : tarjim_language();
    if ($locale !== null) {
        $options['locale'] = $locale;
    }
    if (is_string($config['namespace'] ?? null)) {
        $options['bundle'] = $config['namespace'];
    }

    return $options;
}

/**
 * @param mixed $config
 * @return array<string, mixed>
 */
function tarjim_mappings($config): array
{
    return is_array($config) && is_array($config['mappings'] ?? null) ? $config['mappings'] : [];
}

/** HTML for a page body. The old client's `<span data-tid>` is gone; `skip_assign_tid` is not needed. */
function _T($key, $config = [])
{
    $client = tarjim_use();
    if ($client === null) {
        return htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
    }

    return $client->tHtml((string) $key, tarjim_mappings($config), tarjim_options($config));
}

/**
 * Plain text, NOT escaped: escape it once for where it goes — `{{ }}` in Blade, htmlspecialchars()
 * in an attribute, json_encode() in JavaScript, nothing in an SMS or an email subject.
 */
function _TS($key, $config = [])
{
    $client = tarjim_use();
    if ($client === null) {
        return (string) $key;
    }

    return $client->t((string) $key, tarjim_mappings($config), tarjim_options($config));
}

/**
 * An attribute string for an <img>: `src="…"` plus attributes, every value escaped. Only image
 * attributes are kept; the image's own win over those given, as before. Empty when the key holds no
 * https image.
 */
function _TM($key, $attributes = [])
{
    $client = tarjim_use();
    $attributes = is_array($attributes) ? $attributes : [];
    $config = [];
    foreach (['namespace', 'locale'] as $option) {
        if (isset($attributes[$option])) {
            $config[$option] = $attributes[$option];
            unset($attributes[$option]);
        }
    }
    $image = $client === null ? null : $client->image((string) $key, tarjim_options($config));
    if ($image === null) {
        return '';
    }
    $allowed = ['alt', 'title', 'width', 'height', 'loading', 'class', 'id'];
    $parts = ['src="' . htmlspecialchars($image->url, ENT_QUOTES, 'UTF-8') . '"'];
    foreach (array_merge($attributes, $image->attributes) as $name => $value) {
        if (in_array($name, $allowed, true) && is_scalar($value)) {
            $parts[] = $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }
    }

    return implode(' ', $parts);
}

/**
 * The key in every language the bundle has: ['en' => '…', 'fr' => '…'], '' where a language lacks
 * it. Used to reverse-look-up translated URL slugs. With no copy held it is [] — routes built from
 * it at boot then have no translated slugs, so deploy with `refresh --require-copy` first.
 */
function _TD($key, $config = [])
{
    $client = tarjim_use();
    if ($client === null) {
        return [];
    }
    $options = tarjim_options($config);
    $all = [];
    foreach ($client->locales($options) as $language) {
        $options['locale'] = $language;
        $all[$language] = $client->has((string) $key, $options) ? $client->t((string) $key, [], $options) : '';
    }

    return $all;
}

/**
 * `<meta>` tags from a key whose value is a JSON object, for `['SEO' => 'open_graph']` and
 * `'twitter_card'`; `<title>` for `'page_title'`; a description `<meta>` for `'page_description'`.
 * Every value escaped.
 */
function _TSEO($key, $config = [])
{
    $kind = is_array($config) ? ($config['SEO'] ?? null) : null;
    $value = _TS($key, $config);
    if ($kind === 'page_title') {
        return '<title>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</title>';
    }
    if ($kind === 'page_description') {
        return '<meta name="description" content="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
    }
    if ($kind !== 'open_graph' && $kind !== 'twitter_card') {
        return htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
    }
    $pairs = json_decode($value, true);
    if (!is_array($pairs)) {
        return '';
    }
    $tags = '';
    foreach ($pairs as $property => $content) {
        if (is_string($property) && is_scalar($content) && (string) $content !== '') {
            $tags .= '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars((string) $content, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        }
    }

    return $tags;
}
