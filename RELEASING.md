# Releasing

Published versions are tagged `vX.Y.Z-beta.N` until every item below is done; `1.0.0` comes after.

## Before 1.0.0

- [ ] **The staging check passes** in the serving mode production uses (CDN), and in the other mode
      (origin) against staging or a local API. See below.
- [ ] **`tHtml()` is signed off** for each site moving to the package: the list from
      `scripts/thtml-diff.php` over its released text has been reviewed.
- [ ] **A site runs on the package on staging** through a release and a promote: the new text
      appears within a few minutes, and a refresh that fails keeps the site serving.
- [ ] **The package is on Packagist** under the `tarjim` vendor.
- [ ] **`joylab/tarjim-php-client` and `joylab/tarjim-laravel-client` are marked abandoned** on
      Packagist, pointing at `tarjim/tarjim-php`.

## The staging check

Runs a real refresh against a stage into a temporary folder (readable by you only), reads the copy
back, and removes the folder. It is the only script here that calls a Tarjim server; tests and CI
never do.

```bash
read -rs TARJIM_KEY && export TARJIM_KEY     # the key is read from the environment only
php scripts/staging-check.php --delivery-url=https://…/delivery/<id>/meta --bundle=<name>
unset TARJIM_KEY
```

It prints the serving mode, the release, the bundle's languages and what `tHtml()` would change, and
exits 0 when the release installed and reads back, 1 when something failed, 2 on bad arguments.

`--record=<dir>` also writes the stage's answers as test fixtures, rebuilt from an allow-list of
fields: the signed query's values, URL queries, the distribution name, bundle and namespace names,
key names, values and attribute names are replaced; hashes, sizes, language tags and the release id
are kept. Read them before committing: this repository is public.

## Cutting a release

1. CI is green on `main` for PHP 7.4, 8.2 and 8.4.
2. `Version::SDK` in `src/Version.php` names the version being tagged (it is sent to the server).
3. Tag: `git tag -s vX.Y.Z-beta.N -m "vX.Y.Z-beta.N" && git push origin vX.Y.Z-beta.N`.
4. Packagist picks the tag up from the repository hook.
