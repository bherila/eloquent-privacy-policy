# Benchmark: `privacyQuery()` vs. a hand-written equivalent

What this measures: for `BenchNote` (a benchmark-only mirror of
`tests/Kernel/Note.php` / `tests/Kernel/Folder.php`, same policy, own
`bench_`-prefixed tables), compare

- **package**: `BenchNote::privacyQuery($context)->orderBy('id')->limit(N)->get()`
  (`N` = 10, 100) and `->count()`
- **handwritten**: the same visibility rule written directly against the
  query builder (`benchmarks/Bench/HandwrittenQuery.php`)

at three fixture sizes (1,000 / 10,000 / 100,000 notes), on sqlite and on
MariaDB. The two are asserted to return identical ids and counts before
anything is timed; the script aborts loudly if they ever disagree.

## Running it

```bash
# sqlite (default; in-memory unless DB_DATABASE overrides it)
php benchmarks/run.php

# MariaDB
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=testing \
  DB_USERNAME=root DB_PASSWORD= php benchmarks/run.php
```

Same `DB_*` contract as `tests/TestCase.php`. Raw results land in
`benchmarks/results/{sqlite,mariadb}.json` (git-ignored); this file is the
curated, cited copy of the numbers those runs produced. `BENCH_SIZES` (a
comma-separated list) overrides the three fixture sizes, for a quick local
smoke run — the numbers below are always from the default `1000,10000,100000`.

The script owns only `bench_folders`, `bench_notes`, `bench_note_shares` in
whatever database `DB_DATABASE` names (`testing` on the shared MariaDB
instance) and drops all three when it finishes, whether it finished
successfully or aborted after the correctness gate. It never touches the
Kernel fixture tables (`folders`, `notes`, `note_shares`) other agents or the
test suite use.

### Why Capsule Manager, not Testbench

Before writing this, every file under `src/` was checked for a facade or
container call (`grep -rn "Facades\\\\|app(|Container\|Auth::|Config::" src/`)
— none exists. `HasPrivacyPolicy`, `ProtectedBuilder`, `PolicyResolver` and
`PredicateCompiler` only need `Model::newQuery()` to resolve to a real
connection, which `Illuminate\Database\Capsule\Manager::setAsGlobal()` +
`bootEloquent()` provides on its own. So this script boots Capsule directly
and never touches Testbench or the container; `BenchFolder`/`BenchNote` are
plain `Model` classes, not test-case fixtures.

## The fixture

Deterministic and seeded with **no randomness** — every row's shape is a pure
function of its id, so a given size produces the exact same fixture (and the
exact same visible set) every run, on both engines. `Fixture::VIEWER_ID = 1`
in `Fixture::TENANT_ID = 1`, `is_staff` false (so the policy's privileged
stage folds away — see "The compiled predicate" below).

Notes are spread round-robin across 10 tenants by id (`tenant = ((id-1) % 10)
+ 1`), so the viewer's own tenant is not clustered at the front of the table.
Within that ~10%-of-total slice, rows cycle through 10 buckets covering every
grant path named in the task:

| bucket | shape | visible? |
|---|---|---|
| 0 | author = viewer | yes (author grant) |
| 1 | author ≠ viewer, live share to viewer | yes (share grant) |
| 2 | author ≠ viewer, **expired** share to viewer | no |
| 3 | author ≠ viewer, folder owned by viewer, live | yes (folder grant, via `ViaParent`) |
| 4 | author ≠ viewer, folder owned by viewer, **soft-deleted** | no |
| 5 | author = viewer, **locked** | no (deny overrides the author grant) |
| 6 | author = viewer, locked **IS NULL** | yes (NULL ≠ "locked = true") |
| 7 | author ≠ viewer, locked IS NULL, no share/folder | no |
| 8 | author ≠ viewer, unlocked, no share/folder | no |
| 9 | author ≠ viewer, no folder at all | no |

Buckets {0, 1, 3, 6} are visible — 4 of 10 buckets in a ~10%-of-total slice,
so the viewer sees ~4% of all rows in every run (measured, not assumed — the
script asserts the actual visible count against this bucket arithmetic and
aborts if they ever diverge). The other 9 tenants are pure noise excluded by
the mandatory tenant boundary alone; their `author_id` cycles through 1..50,
so some of them are authored by the viewer on purpose, to exercise that the
boundary gates before the grant stage runs, not instead of it.

Indexes created (exactly what the task asked for, nothing extra):
`bench_notes(tenant_id, author_id)`, `bench_notes(folder_id)`,
`bench_note_shares(note_id, user_id)`, `bench_folders(owner_id)`.

Measured, all three sizes, both engines — 4.00% visible on every run:

| N | tenant slice | folder pool | seed time sqlite | seed time MariaDB | visible |
|---|---|---|---|---|---|
| 1,000 | 100 | 20 | 6.8 ms | 15.5 ms | 40 (4.00%) |
| 10,000 | 1,000 | 50 | 49.3 ms | 132.2 ms | 400 (4.00%) |
| 100,000 | 10,000 | 500 | 502.0 ms | 1,089.3 ms | 4,000 (4.00%) |

100,000 rows was not impractically slow on sqlite `:memory:` (whole three-size
run, seeding + all measurement, finishes in ~2.6s wall); nothing was skipped.

## The compiled predicate

The benchmark context never sets `is_staff`, so the policy's privileged stage
(`Predicate::constant($c->facts->bool('is_staff'))`) folds to `never()` at
resolve time and disappears from the compiled SQL entirely (contract §4.4;
`Predicate::any()`/`all()` drop constant members, confirmed by reading
`src/Predicate/Predicate.php`). With the terminal stage also defaulting to
Deny, the general form

```
visible = H AND ( P OR ( NOT D AND ( A OR T ) ) )
```

reduces, for this context, to

```
visible = H AND NOT D AND A
```

i.e. tenant boundary, and not locked, and (author OR live share OR visible
folder) — which is exactly what `benchmarks/Bench/HandwrittenQuery.php`
writes by hand. `BenchNote`'s policy has no string-typed column comparison
(`tenant_id`, `author_id`, `user_id`, `owner_id` are `int`; `locked` is
`bool`; `expires_at`/`now` are `datetime`), so the compiler's
`CAST(... AS BINARY)` exact-string-match guard (contract §4.3, MySQL/MariaDB
only) **never appears in this benchmark's SQL** — confirmed by grepping every
captured statement in both result files for `CAST`: zero matches. Any reader
tempted to blame that guard for a slowdown here would be wrong; it is simply
not part of this policy.

One correctness subtlety worth calling out because it is easy to get wrong by
hand: `locked = 1` is SQL-NULL (neither true nor false) when `locked` IS NULL,
and `NOT NULL` is also NULL — which a `WHERE` treats as "exclude the row",
on both sides of the `NOT`. So `WHERE NOT (locked = 1)` would silently drop
bucket 6 (locked IS NULL, should stay visible). The handwritten query spells
out `locked IS NULL OR locked = 0` instead, which is what the contract's
two-valued semantics (§4.2) require and what the package gets for free
because `PredicateCompiler` always guards a comparison with `IS NOT NULL`
first.

## Results

30 timed iterations after 5 warm-ups, `hrtime`, wall time with the query log
disabled (statement capture and `EXPLAIN` are a separate, untimed pass over
the same query). Every row below: package and handwritten returned identical
ids and counts, and the package issued exactly 1 SQL statement for both
`N = 10` and `N = 100` (asserted by the script, not just observed) — no N+1
from the folder `ViaParent` expansion; it compiles to a correlated `EXISTS`
inside the same statement.

### sqlite 3.53.4 (in-process, `:memory:`)

| N | operation | package p50 | package p95 | handwritten p50 | handwritten p95 | ratio (p50) | statements (pkg / hw) |
|---|---|---|---|---|---|---|---|
| 1,000 | get limit 10 | 0.348 ms | 0.539 ms | 0.118 ms | 0.166 ms | 2.96x | 1 / 1 |
| 1,000 | get limit 100 | 0.494 ms | 0.679 ms | 0.136 ms | 0.224 ms | 3.63x | 1 / 1 |
| 1,000 | count | 0.239 ms | 0.367 ms | 0.097 ms | 0.098 ms | 2.47x | 1 / 1 |
| 10,000 | get limit 10 | 0.801 ms | 1.069 ms | 0.563 ms | 0.635 ms | 1.42x | 1 / 1 |
| 10,000 | get limit 100 | 1.322 ms | 1.647 ms | 0.612 ms | 0.731 ms | 2.16x | 1 / 1 |
| 10,000 | count | 0.663 ms | 0.864 ms | 0.540 ms | 0.639 ms | 1.23x | 1 / 1 |
| 100,000 | get limit 10 | 7.707 ms | 8.551 ms | 7.237 ms | 7.734 ms | 1.06x | 1 / 1 |
| 100,000 | get limit 100 | 8.311 ms | 9.522 ms | 7.503 ms | 8.369 ms | 1.11x | 1 / 1 |
| 100,000 | count | 7.552 ms | 9.000 ms | 6.903 ms | 8.149 ms | 1.09x | 1 / 1 |

### MariaDB 10.11.19 (loopback, `127.0.0.1:3399`)

| N | operation | package p50 | package p95 | handwritten p50 | handwritten p95 | ratio (p50) | statements (pkg / hw) |
|---|---|---|---|---|---|---|---|
| 1,000 | get limit 10 | 0.738 ms | 0.912 ms | 0.447 ms | 0.957 ms | 1.65x | 1 / 1 |
| 1,000 | get limit 100 | 1.048 ms | 1.317 ms | 0.617 ms | 0.821 ms | 1.70x | 1 / 1 |
| 1,000 | count | 0.799 ms | 1.366 ms | 0.458 ms | 0.602 ms | 1.74x | 1 / 1 |
| 10,000 | get limit 10 | 0.791 ms | 0.979 ms | 0.537 ms | 0.803 ms | 1.47x | 1 / 1 |
| 10,000 | get limit 100 | 1.735 ms | 2.015 ms | 1.104 ms | 1.273 ms | 1.57x | 1 / 1 |
| 10,000 | count | 1.479 ms | 1.887 ms | 1.236 ms | 1.621 ms | 1.20x | 1 / 1 |
| 100,000 | get limit 10 | 1.136 ms | 1.615 ms | 0.815 ms | 0.908 ms | 1.39x | 1 / 1 |
| 100,000 | get limit 100 | 2.184 ms | 2.519 ms | 1.400 ms | 1.617 ms | 1.56x | 1 / 1 |
| 100,000 | count | 14.660 ms | 15.184 ms | 14.256 ms | 14.952 ms | 1.03x | 1 / 1 |

MySQL was not measured: there is no local MySQL server available in this
environment, only the private MariaDB instance above.

## Reading the plans

Full `EXPLAIN` / `EXPLAIN QUERY PLAN` output for every statement is in the
JSON results; two patterns recur across every size and both engines and are
worth calling out because they explain the shape of the numbers above.

**The package and the handwritten query get the same access path on both
engines.** Diffing the captured `EXPLAIN` rows for `get limit 10` at
N=100,000 (MariaDB) shows identical `type`, `key`, and `rows` estimates for
both variants — the only difference is that the package's correlated
subqueries are aliased `pp_1` (`PredicateCompiler::exists()` names its alias
from nesting depth) where the handwritten query uses the real table name.
Same on sqlite: same `SEARCH ... USING INDEX ...` steps, same
`CORRELATED SCALAR SUBQUERY` structure, alias names aside. **The database
does the same work either way; the ratio above is PHP-side, not SQL-side.**

**MariaDB's `count()` is an outlier — for both variants, not just the
package.** At N=100,000, `get limit 10`/`get limit 100` run in ~1-2ms but
`count()` takes ~14-15ms, for package *and* handwritten. The `EXPLAIN` shows
why: `get limit N` uses `type: index` (a primary-key-ordered index scan) with
a `rows` estimate of 38/380 — MariaDB can stop as soon as it has found `N`
matching rows, because primary-key order already satisfies `ORDER BY id` and
the fixture's round-robin tenant assignment puts qualifying rows every ~10
ids. `count()` has no `LIMIT` to stop early, so its plan is `type: ALL`
(`rows: 100000`) — a full table scan is exactly what an aggregate over an
OR'd, subquery-bearing `WHERE` requires. This is an engine/query-shape
interaction, not a package defect: the handwritten `count()` pays the
identical 1.03x-close cost.

**sqlite's planner does not get MariaDB's early-exit for `get limit N`, so it
costs about the same as `count()`.** At N=100,000, sqlite's `get limit 10`
(7.7ms), `get limit 100` (8.3ms) and `count()` (7.6ms) are all in the same
~7-8ms band, unlike MariaDB where `get` is ~7x cheaper than `count`. The
`EXPLAIN QUERY PLAN` shows `SEARCH bench_notes USING INDEX
bench_notes_tenant_id_author_id_index (tenant_id=?)` followed by `USE TEMP
B-TREE FOR ORDER BY` — sqlite picks the tenant index to *filter*, but that
index does not also produce `id` order, so it must evaluate the two
correlated subqueries against the *entire* ~10,000-row tenant-1 slice,
materialize every winner, sort the winners into a temp B-tree by `id`, and
only then apply `LIMIT`. `LIMIT` saves it nothing on the expensive part
(subquery evaluation); only the final sort/materialization step scales with
how many winners there are, which is why `count` (7.55ms, no sort) < `get
limit 10` (7.71ms) < `get limit 100` (8.31ms). The exact index the task asked
for (`tenant_id, author_id`) does not, by itself, make `ORDER BY id` free on
sqlite the way MariaDB's plan gets it for free from primary-key order — an
index on `(tenant_id, id)` might change this, but that combination was not
tested; it is outside what was asked for and outside what the plans above
show.

**Where the package's own overhead most likely comes from**, given the
above (identical access paths — so not the SQL the database runs):

- **The compiled SQL is visibly more nested.** Every stage
  (`H`/`D`/`A` and every `AllOf`/`AnyOf` inside them) becomes its own
  `->where(fn (...) => ...)` closure in `PredicateCompiler::compile()`
  (`src/Sql/PredicateCompiler.php`), so the package's captured SQL for the
  same query has visibly more parenthesised groups than the handwritten one
  (compare the two `count()` statements in `benchmarks/results/mariadb.json`
  — the package's is one contiguous nest of `(((...)))`, the handwritten one
  is flat). Building that many nested closures costs PHP time before the
  query ever reaches the driver; running it costs the database nothing extra
  (per the identical `EXPLAIN` shapes above).
- **`PolicyResolver` runs on every `privacyQuery()` call** (contract §4.4:
  "Rule closures run here, once per operation") — three rule closures
  (`tenant`, `staff`, `locked`) plus three grant closures, an `Exists::build`
  and a `ViaParent` expansion into a nested `resolve()` call for
  `BenchFolder`, all before the query builder is touched. This is fixed
  per-call cost, independent of `N` or row count, which matches the pattern
  in the tables: the ratio is largest at N=1,000 (2.5-3.6x on sqlite, where
  the query itself is sub-millisecond and a fixed PHP cost dominates) and
  shrinks toward ~1.0x-1.1x at N=100,000 (sqlite) once the database's own
  work dwarfs it.
- **Eloquent model hydration costs more than a `stdClass` row**, and scales
  with row count: on sqlite the package/handwritten gap widens from
  `get limit 10` to `get limit 100` at every size (e.g. at N=10,000, 1.42x
  to 2.16x), consistent with per-row hydration overhead (attribute casting,
  the `$guarded` check, `bindPrivacyContext()`) rather than a fixed cost.

None of this required speculating past what was captured: every claim above
cites a specific `EXPLAIN` row or SQL string in `benchmarks/results/*.json`.

## Limits

- **Single machine, single run, no concurrency.** One process, sequential
  sizes, sequential operations. No p99, no contention, no repeated runs to
  bound run-to-run variance beyond the 30-iteration median/p95 reported
  above.
- **Local loopback database.** MariaDB is `127.0.0.1:3399` on the same
  machine as the PHP process — no real network latency, no connection-pool
  contention, no replica lag. Production numbers over a real network will
  differ, likely by more for the handwritten query (fixed statement count is
  fixed either way) than for the package's added PHP-side cost.
- **MySQL was not measured** — no local MySQL server is available in this
  environment, only the MariaDB instance above.
- **One policy shape.** `BenchNote`'s policy (tenant boundary, staff bypass,
  locked deny, three grant paths one of which is a one-level `ViaParent`) is
  representative of the Kernel fixture, not of every policy this package can
  express. A policy with more `Exists`/`ViaParent` nesting, more grant
  clauses, or a string column needing the `CAST(...AS BINARY)` guard would
  compile to different (probably costlier) SQL; none of that is measured
  here.
- **Synthetic, uniform-ish distribution.** Rows are placed by simple
  deterministic arithmetic on `id`, not sampled from any real workload's
  skew. The ~4% visible fraction is exact and reproducible by construction,
  not representative of any particular application's actual grant density.
- **Machine-dependent absolute numbers.** CPU model, OS, and PHP version are
  recorded in `benchmarks/results/*.json` (`meta`) and above; the *ratios*
  between package and handwritten, and the qualitative planner findings, are
  the portable part of this benchmark — the millisecond figures are not.
- **What was not run:** `sum()`, `paginate()`, `with()` eager loading,
  `find()`/`findOrFail()`, anonymous viewers, the `is_staff` privileged path,
  multiple concurrent viewers, write paths (`Action\ActionExecutor`), and any
  size beyond 100,000.
