# Developer Guidelines — nails/module-queue

This document captures project-specific information to help advanced contributors work efficiently on this repository.

## Build & Configuration

- Runtime
  - PHP: ^8.2 (tested locally with PHP 8.4.14)
  - Composer installs dependencies with `minimum-stability: dev` and `prefer-stable: true`.
- Install
  - Ensure PHP extensions commonly used by the Nails stack are installed (mbstring, json, pdo, curl, openssl, intl recommended).
  - Install dependencies:
    - `composer install`
- Autoloading
  - PSR-4:
    - `Nails\Queue\` → `src/`
    - Tests: `Tests\Queue\` → `tests/`
- Module context
  - This is a Nails module (`extra.nails` in `composer.json`):
    - Depends on `nails/common`, `nails/module-console`, and `nails/module-cron` (all on `dev-feature/pre-new-admin` branches).
  - Admin asset autoloading entries are present but empty.

## Static Analysis (PHPStan)

- Config: `.phpstan/config.neon`
  - `level: 0` (baseline lenient), paths: `src` and `tests`
  - Bootstraps `.phpstan/constants.php` which sets framework constants like `NAILS_COMMON_PATH`, `BASEPATH`, and `NAILS_DB_PREFIX` to satisfy framework-dependent references during analysis.
- Run:
  - Composer script: `composer analyse`
  - Direct: `./vendor/bin/phpstan analyse -c .phpstan/config.neon`

## Testing

- Framework
  - PHPUnit 10.x (via `require-dev`)
  - Project config: `phpunit.xml`
    - Bootstrap: `tests/bootstrap.php` — calls `Nails\Testing::bootstrapModule(__FILE__)` to wire up the module under the Nails testing harness.
    - Test discovery: `./tests` with file suffix `Test.php`.
    - Example env var for tests: `PRIVATE_KEY=abc123` is injected via `<php><env .../></php>`; can be read via `getenv('PRIVATE_KEY')` or `$_ENV['PRIVATE_KEY']`.
- Commands
  - Run full suite:
    - Composer: `composer test`
    - Direct: `./vendor/bin/phpunit`
  - Run a single test file:
    - `./vendor/bin/phpunit tests/ConstantsTest.php`
  - Filter by test name (uses `--filter` with PCRE):
    - `./vendor/bin/phpunit --filter test_module_slug_is_correct`
- Adding tests
  - Namespace your tests under `Tests\Queue` and place them in `tests/` with the `*Test.php` suffix.
  - Ensure the module is loaded via the existing bootstrap; no additional setup is typically required.
  - Example (validated locally during preparation):
    - Create `tests/SmokeTest.php`:
      ```php
      <?php
      namespace Tests\Queue;
      use PHPUnit\Framework\TestCase;
      class SmokeTest extends TestCase {
          public function test_bootstrap_sets_private_key_env() {
              $this->assertSame('abc123', getenv('PRIVATE_KEY'));
          }
      }
      ```
    - Run: `./vendor/bin/phpunit` → should pass.
    - Remove the temporary file afterwards; it was used only to demonstrate the process.
- Existing example
  - `tests/ConstantsTest.php` asserts the module slug constant `Nails\Queue\Constants::MODULE_SLUG` is `nails/module-queue`.

## Coding Style & Conventions

- Follow the prevailing style in `src/`:
  - Namespaces rooted at `Nails\Queue`.
  - Service classes live under `src/Service`, Console commands under `src/Console`, etc., matching folder structure.
  - Prefer strict types and typed properties/params/returns (PHP 8.2+). Mirror the module’s existing typing practices when editing nearby code.
- Tests: use PHPUnit 10 conventions
  - Extend `PHPUnit\Framework\TestCase`.
  - Use `self::assert*`/`$this->assert*` assertions; avoid deprecated annotations from older PHPUnit versions.

## Project Notes Specific to Nails Modules

- The testing bootstrap relies on `Nails\Testing::bootstrapModule(__FILE__)` (provided by `nails/common`). This simulates the Nails environment so the module’s services, constants, and CodeIgniter-y globals resolve without a full app.
- Some code paths may assume CodeIgniter constants and paths (see `.phpstan/constants.php`). When adding code which directly depends on CI paths, consider guarding them behind interfaces/services so unit tests and static analysis do not require a full CI runtime.

## Useful Paths

- `composer.json` — dependencies, autoload, and scripts (`test`, `analyse`).
- `phpunit.xml` — test bootstrap, discovery rules, and env.
- `tests/bootstrap.php` — module bootstrap into the Nails testing harness.
- `.phpstan/config.neon` and `.phpstan/constants.php` — static analysis configuration and framework constant stubs.

## Quick Start

1) Install and build
- `composer install`

2) Run tests
- `composer test`

3) Static analysis
- `composer analyse`

4) Add a test
- Create `tests/FooTest.php` under `Tests\\Queue` namespace; run `composer test`.

This file is the only artifact added by this documentation task; any temporary test files used during validation have been removed to leave the working tree clean.
