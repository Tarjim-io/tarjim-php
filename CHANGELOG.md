# Changelog

All notable changes to this package are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/); before `1.0.0` the API may change in any release.

## [0.1.0-alpha.1] - 2026-11-23

The first version.

### Added

- **Refresh:** `Client::refresh()` and `vendor/bin/tarjim-php refresh` install the release a
  distribution's stage points at into a local folder. Every file is verified before install, the
  install is atomic, and a failed refresh never removes what is held. Both delivery modes (origin
  and CDN) are supported. A check runs at most once a minute per folder, and `Retry-After` is
  honoured.
- **Status:** `Client::status()` and `tarjim-php status` report the held release and whether it is
  stale.
- **Lookups:** `t()`, `tHtml()`, `has()`, `image()` and `locales()` read the local copy only.
  - They never call Tarjim, write or throw.
  - Languages are chosen as the JavaScript SDK chooses them.
  - `keyCase: 'lower'` folds key names.
- **Placeholders:** `{name}`, and `%%name%%` with `legacyPercentPlaceholders`.
- **`tHtml()`:** returns safe HTML. It keeps a short list of tags, `class`, and `target="_blank"`
  with `rel="noopener noreferrer"`, and escapes inserted values.
- **Export pulls:** `exports()->pull()` and `tarjim-php export` pull a bundle export into a
  folder. The pull owns only the files it wrote, replaces them all or nothing, and checks every
  zip entry.
- **Migration:** a guide from `joylab/tarjim-php-client`, and a helper file that keeps `_T`, `_TS`,
  `_TM`, `_TD` and `_TSEO` working.
- **Tools:** `scripts/staging-check.php` checks a real stage end to end; `scripts/thtml-diff.php`
  lists the released values `tHtml()` would show differently.

[0.1.0-alpha.1]: https://github.com/Tarjim-io/tarjim-php/releases/tag/v0.1.0-alpha.1
