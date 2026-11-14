# Contributing

Thank you for helping. This file says how to set up, test and send a
change.

## Setup

```sh
git clone https://github.com/IndianOpinion/feed-health.git
cd feed-health
composer install
```

PHP 8.1 or later. No WordPress install is needed for the tests:
`tests/stubs/` stands in for the parts of WordPress the plugin calls.

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # phpcs, PSR-12
composer check     # all three
```

The MySQL integration tests run when `FEED_HEALTH_TEST_DSN` is set:

```sh
FEED_HEALTH_TEST_DSN="mysql:host=127.0.0.1;dbname=feedhealth" \
FEED_HEALTH_TEST_USER=root FEED_HEALTH_TEST_PASSWORD=secret composer test
```

They create and drop tables with the prefix `fhtest_`; use a throwaway
database.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- PHP 8.1 syntax only: no readonly classes, DNF types or `true` type.
- Typed exceptions; no `@` error suppression; no `eval`; no shell.
- Escape every output in the admin screens; capability and nonce checks
  on every action.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One change per pull request, with tests for new behaviour.
- Describe what changed and why. Link the PRD case (docs/PRD.md) when the
  change touches behaviour.
- CI must be green: lint, phpcs, PHPStan and PHPUnit on PHP 8.1, 8.2 and
  8.3.
- A DCO-style sign-off is not required.
