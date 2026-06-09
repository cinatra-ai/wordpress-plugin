# Testing Patterns

**Analysis Date:** 2026-06-09

## Test Framework

**Runner:** Not applicable — no PHP test framework is installed. There is no `phpunit.xml`, `composer.json`, `package.json`, `jest.config.*`, or `vitest.config.*` in the repository.

**Assertion Library:** Not applicable.

**Run Commands:**
```bash
# No test runner available. CI uses static checks only:
php -l cinatra.php          # PHP syntax lint
php -l uninstall.php        # PHP syntax lint
```

## Test File Organization

**Location:** No test files exist in the repository. There is no `tests/`, `test/`, or `__tests__/` directory.

**Naming:** Not applicable.

**Structure:** Not applicable.

## CI Quality Checks (Substituting for Tests)

The CI pipeline (`.github/workflows/ci.yml`) runs three jobs on every push to `main` and every pull request:

**`php-lint` (blocking):**
- Runs `php -l` on every `.php` file excluding `vendor/`
- Catches syntax errors and parse failures
- PHP 8.2 runtime used in CI; plugin requires PHP 7.4+

**`readme-validate` (blocking):**
- Asserts `readme.txt` exists and contains required `Stable tag:` and `License:` headers
- Asserts `Stable tag` in `readme.txt` matches `Version:` in `cinatra.php` plugin header
- Prevents version skew between the two required WordPress.org files

**`plugin-check` (non-blocking, `continue-on-error: true`):**
- Runs the official `WordPress/plugin-check-action@v1`
- Informational only — failures do not block merge
- Noted in CI comments as "Optional WPCS once initial noisy findings are triaged"

## Mocking

**Framework:** Not applicable — no test framework present.

**Patterns:** Not applicable.

## Fixtures and Factories

**Test Data:** Not applicable.

**Location:** Not applicable.

## Coverage

**Requirements:** No coverage tooling or enforcement. No target percentage defined.

**View Coverage:** Not applicable.

## Test Types

**Unit Tests:** Not implemented. The plugin's logic (sanitizers, validators, REST handlers, option migration) has no automated unit test coverage.

**Integration Tests:** Not implemented. No WP_Mock, Brain Monkey, or wp-env integration test setup.

**E2E Tests:** Not implemented.

## Recommendations for Adding Tests

If tests are introduced, the standard WordPress plugin testing approach is:

**PHPUnit + WP_Mock or Brain Monkey** for unit testing pure functions like `cinatra_sanitize_url_option`, `cinatra_sanitize_subscriptions_option`, `cinatra_validate_event_type`, `cinatra_validate_target_url`.

**wp-env + PHPUnit** (WordPress integration test environment) for testing REST endpoints (`cinatra/v1/webhooks`) and hook-wired behavior (settings registration, option migration).

Suggested file layout when tests are added:
```
tests/
├── Unit/
│   ├── SanitizersTest.php   # cinatra_sanitize_* functions
│   └── ValidatorsTest.php   # cinatra_validate_* functions
├── Integration/
│   └── WebhooksRestTest.php # REST endpoint CRUD behavior
└── bootstrap.php
```

---

*Testing analysis: 2026-06-09*
