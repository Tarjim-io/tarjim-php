<?php

// The old client's global helpers, mapped onto tarjim/tarjim-php. Copy this file into your app, load
// it once (Composer "files" autoload, or require it at boot), and call tarjim_use() with your client.
//
// The client has no "current language" of its own: set it with tarjim_language() wherever the app
// switches language (a middleware, before rendering each email), and every helper passes it on.

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

function tarjim(): Client
{
    $client = tarjim_use();
    if ($client === null) {
        throw new LogicException('call tarjim_use() with a client before using the Tarjim helpers');
    }

    return $client;
}

/** The language the helpers ask for; pass a tag to change it. */
function tarjim_language(?string $set = null): ?string
{
    static $language = null;
    if ($set !== null) {
        $language = $set;
    }

    return $language;
}

/** @return array<string, string> lookup options from the old `namespace` option and the current language */
function tarjim_options(array $config): array
{
    $options = [];
    if (tarjim_language() !== null) {
        $options['locale'] = tarjim_language();
    }
    if (isset($config['namespace']) && is_string($config['namespace'])) {
        $options['bundle'] = $config['namespace'];
    }

    return $options;
}

/** HTML for a page body. The old client's `<span data-tid>` is gone; `skip_assign_tid` is not needed. */
function _T($key, $config = [])
{
    return tarjim()->tHtml((string) $key, $config['mappings'] ?? [], tarjim_options($config));
}

/**
 * Plain text, NOT escaped: escape it once for where it goes — `{{ }}` in Blade, htmlspecialchars()
 * in an attribute, json_encode() in JavaScript, nothing in an SMS or an email subject.
 */
function _TS($key, $config = [])
{
    return tarjim()->t((string) $key, $config['mappings'] ?? [], tarjim_options($config));
}

/**
 * An attribute string for an <img>: `src="…"` plus attributes, every value escaped. The image's own
 * attributes win over those given, as before. Empty when the key holds no https image.
 */
function _TM($key, $attributes = [])
{
    $config = [];
    if (isset($attributes['namespace'])) {
        $config['namespace'] = $attributes['namespace'];
        unset($attributes['namespace']);
    }
    $image = tarjim()->image((string) $key, tarjim_options($config));
    if ($image === null) {
        return '';
    }
    $parts = ['src="' . htmlspecialchars($image->url, ENT_QUOTES, 'UTF-8') . '"'];
    foreach (array_merge($attributes, $image->attributes) as $name => $value) {
        if (is_string($name) && preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $name) === 1 && is_scalar($value)) {
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
    $client = tarjim();
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
 * `'twitter_card'`; `<title>` for `'page_title'`. Every value escaped.
 */
function _TSEO($key, $config = [])
{
    $kind = $config['SEO'] ?? null;
    $value = tarjim()->t((string) $key, [], tarjim_options($config));
    if ($kind === 'page_title') {
        return '<title>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</title>';
    }
    if ($kind === 'page_description') {
        return '<meta name="description" content="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
    }
    if ($kind !== 'open_graph' && $kind !== 'twitter_card') {
        return (string) $key;
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
