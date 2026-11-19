# tarjim/tarjim-php

Serves text released from [Tarjim](https://tarjim.io) to a PHP application from a local copy,
refreshed from a Tarjim delivery distribution.

**Work in progress — not released.** Nothing here is ready to install yet.

Requires PHP 7.4 or later with `ext-json` and `ext-curl`. No other Composer packages.

## Development

```bash
composer update
vendor/bin/phpunit                    # unit tests and the real-curl tests against a local php -S server
vendor/bin/phpstan analyse
```

Tests never call a Tarjim server.

## Licence

MIT
