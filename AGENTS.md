# AGENTS.md

Instructions for AI coding agents (and human contributors) working in this repo.

## What this package is

`bherila/eloquent-privacy-policy` is a SQL-first privacy policy enforcement
library for **existing** Eloquent models. It is not an ORM replacement, not a
permissions or authorization service, and it does not own your schema or your
auth stack. A policy is declared once as data and interpreted twice: compiled
into the `WHERE` clause of a protected query, and evaluated in PHP against a
row snapshot. Writes go through a separate action executor.

The guarantee covers the protected API only. Ordinary Eloquent queries are
unchanged, and application PHP holding database credentials can always run an
unprotected query. `docs/contract.md` is the source of truth for the API and
its semantics; code, tests and the README follow it. Never describe a path as
protected unless a test proves it.

## Running tests

The test suite runs unmodified against sqlite (the default) and against real
MySQL / MariaDB servers, controlled entirely by environment variables. This
contract is defined in `tests/TestCase.php` — do not change the variable
names or defaults there without updating every caller (CI, other authors'
local setups):

- `DB_CONNECTION`: `sqlite` | `mysql` | `mariadb` (default: `sqlite`)
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

```bash
# sqlite (default, no external services needed)
vendor/bin/phpunit --testsuite Package

# against a real engine
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD= \
  vendor/bin/phpunit --testsuite Package

# concurrency-sensitive tests require a real engine and live in their own suite
DB_CONNECTION=mysql ... vendor/bin/phpunit --testsuite Concurrency
```

Tests that only make sense against a real engine call `$this->requiresRealEngine()`
and skip cleanly on sqlite; do not gate them with ad hoc conditionals instead.

## Static analysis

```bash
vendor/bin/phpstan analyse
```

Level 8, via Larastan, over `src` and `tests`, no baseline. A finding is fixed,
not suppressed.

## This is a public repository

Docs, tests, fixtures, commit messages, and PR text use only generic wording
and `*.example.test` hosts. Never name, reference, or imply which application
consumes this package, its infrastructure, or its deployment topology — that
information belongs only in private, non-public repos. Test fixtures are
synthetic data only; never real records, real identifiers, or anything copied
from a consuming application.

## Merges and releases

Nothing in this repo is merged, tagged, or released without the maintainer.
An independent review (see the maintainer's own review workflow) gates every
merge — do not merge on green CI alone.
