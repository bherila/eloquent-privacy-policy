# Contract

This document is the single source of truth for the package's public API and
semantics. Code, tests and the README follow it; when they disagree, this file
is right and the other is a bug.

Status of each section is marked **[slice 1]** (implemented and tested in the
first reviewable slice) or **[staged]** (specified here, not yet implemented —
calling it throws `UnsupportedProtectedOperation` or it simply does not exist).

Namespace root: `BWH\EloquentPrivacyPolicy`.

## 1. What this is

A small SQL-first enforcement library for existing Eloquent models. A policy is
declared once as data and interpreted twice: compiled into the `WHERE` clause of
a collection query, and evaluated in PHP against a row snapshot. It is not an
ORM, not a permissions service, and not a sandbox: application PHP that holds
database credentials can always run an unprotected query. The guarantee covers
the **protected API** described here and nothing else.

Ordinary queries on a model (`Model::query()`, `Model::find()`, relationships on
an ordinarily loaded instance) are unchanged by adopting the package.

## 2. Decisions, stages, reduction **[slice 1]**

`Decision` is an enum: `Allow`, `Deny`, `Skip`.

A `RuleSet` has five fixed stages, evaluated in this order:

| # | Stage      | Permitted outcomes | Reduction                                  |
|---|------------|--------------------|--------------------------------------------|
| 1 | mandatory  | `Deny`, `Skip`     | any `Deny` → **Deny**; otherwise continue  |
| 2 | privileged | `Allow`, `Skip`    | any `Allow` → **Allow**; otherwise continue|
| 3 | deny       | `Deny`, `Skip`     | any `Deny` → **Deny**; otherwise continue  |
| 4 | grant      | `Allow`, `Skip`    | any `Allow` → **Allow**; otherwise continue|
| 5 | terminal   | `Allow`, `Deny`    | the configured value; default **Deny**     |

A mandatory rule is a boundary: `Skip` means "boundary satisfied". A privileged
`Allow` cannot skip a mandatory `Deny`, because mandatory is reduced first.

**Entered-stage completion.** When a stage is entered, every rule in it is
evaluated and every outcome validated *before* the stage is reduced. There is no
race-to-Allow: a stage in which one rule allows and another errors is an error.
Stages after a decisive one are not entered, so their rules do not run and
cannot error.

**Errors are never `Skip` and never an implicit `Allow`.** The following throw
(all extend `PrivacyException`):

| Exception                        | Raised when                                                              |
|----------------------------------|--------------------------------------------------------------------------|
| `MissingContext`                 | no context was supplied (distinct from an explicit anonymous context)    |
| `MissingFact`                    | a rule read a fact the context does not carry (absent ≠ `null`)          |
| `MissingAttribute`               | runtime evaluation needed a column the snapshot does not contain         |
| `InvalidOutcome`                 | a rule returned an outcome its stage does not permit                     |
| `AttributeTypeMismatch`          | a raw value cannot be normalised to the column's declared type           |
| `PolicyCycle`                    | `ViaParent` expansion revisits a model already on the expansion stack    |
| `PolicyNotRegistered`            | a protected path reached a model with no policy                          |
| `UncompilablePolicy`             | a collection policy contains something the SQL compiler cannot express   |
| `UnsupportedProtectedOperation`  | a protected builder/model was asked to do something outside the matrix   |
| `InvalidIdentifier`              | a table, column, operator or direction is not a plain trusted identifier |
| `ActionDenied`                   | an action's rule set reduced to `Deny` (§6)                              |
| `Action\MissingLockedRow`        | an anchor, the target, or a parent named by a foreign key does not exist |
| `StageEvaluationFailed`          | one or more rules in an entered stage threw; wraps all of them, ordered by rule id |

**Permutation invariance.** For a valid rule set, reordering rules within a
stage changes neither the decision nor whether an error is raised nor the set of
wrapped errors. Rules must be pure and must not depend on each other.

There is no built-in administrator bypass. An application that wants one writes
a privileged rule, and the mandatory stage still applies to it.

## 3. Context **[slice 1]**

`Context\PrivacyContext` is a `final readonly` value object. Every part is a
scalar snapshot; the constructor rejects objects, so a mutable application user
model can never be captured.

| Part           | Type                         | Meaning                                                        |
|----------------|------------------------------|----------------------------------------------------------------|
| `viewer`       | `Viewer`                     | who is asking: `Viewer::anonymous()` or `Viewer::identified(int\|string $id, string $type = 'user')` |
| `capacity`     | `?Capacity`                  | the capacity the viewer acts in (`kind`, scalar `id`); not an identity |
| `scope`        | `ResourceScope`              | validated resource-boundary keys, e.g. `['tenant_id' => 7]`     |
| `restrictions` | `CredentialRestrictions`     | `unrestricted()` or `only([...abilities])`; restrict, never expand |
| `operation`    | `Operation`                  | stable operation identity, e.g. `record.list`                   |
| `facts`        | `Facts`                      | scalar / list-of-scalar facts resolved for this operation       |
| `now`          | `DateTimeImmutable` (UTC)    | the one clock both interpreters use; SQL never calls `NOW()`    |

Contexts are built by a trusted application adapter after its own prerequisites
(authentication, feature gates, section access) have succeeded. Nothing in a
context is taken from client-supplied role or permission claims.

`Viewer::id()` on an anonymous viewer throws `MissingFact`. A `RuleSet` denies anonymous
viewers outright (constant `false` predicate / `Deny`) unless it was declared
with `->allowAnonymous()`, in which case its rules must branch on
`$context->viewer->isAnonymous()` themselves.

Facts are operation-local. The package keeps no static or cross-operation cache
of contexts, facts, decisions, or protected models. Policy *definitions* are
context-free and immutable and may be cached per model class.

## 4. Predicates: one representation, two interpreters **[slice 1]**

A read rule is a `Policy\PredicateRule`:

```php
interface PredicateRule {
    public function id(): string;                         // stable, unique in its stage
    public function predicate(PrivacyContext $context): Predicate;
}
```

The rule's *stage* gives the predicate its meaning: for a row where the
predicate is true the rule yields its stage's decisive outcome (mandatory: the
boundary is satisfied; privileged/grant: `Allow`; deny: `Deny`), otherwise
`Skip` (mandatory: `Deny`). `Rule::of('id', fn (PrivacyContext $c) => …)` is the
closure form; the closure builds IR, it is not itself interpreted.

### 4.1 Node set

| Node                                   | Meaning                                                     |
|----------------------------------------|-------------------------------------------------------------|
| `Predicate::always()` / `never()`      | constants                                                   |
| `Col::int\|string\|bool\|datetime($name)` | typed column reference (trusted schema configuration)    |
| `->eq($v)`, `->in([...])`              | all types. `$v` is never `null`                             |
| `->lt/lte/gt/gte($v)`                  | `int` and `datetime` only                                   |
| `->isNull()`, `->isNotNull()`          | the only way to talk about `NULL`                           |
| `->eqCol(Col $other)`                  | same declared type on both sides                            |
| `Predicate::all(...)`, `any(...)`, `not($p)` | conjunction, disjunction, negation                    |
| `Exists::in($table)->match($outerCol, $innerCol)->where($p)` | constrained correlated `EXISTS`; inner predicate is over the inner table |
| `ViaParent::of($fk, ParentModel::class)` | "the parent row is visible under the parent's read policy"; expands to an `Exists` over the parent table, adds `deleted_at IS NULL` when the parent soft-deletes, detects cycles |

Column and table names come from policy code and are validated as identifiers;
values are always bound. Nothing else is expressible. In particular there is no
raw SQL node and no opaque-callback node: a collection policy that cannot be
compiled is rejected with `UncompilablePolicy`, never fetched and filtered.

### 4.2 Two-valued semantics

Every node evaluates to `true` or `false`. There is no `UNKNOWN`.

- A comparison against a `NULL` column is **false**; its negation is therefore
  **true**. The compiler guarantees this by emitting
  `(col IS NOT NULL AND col = ?)`, never a bare `col = ?`, so `NOT` behaves the
  same in SQL and in PHP.
- `in([])` is `never()`.
- An attribute that is *absent* from a runtime snapshot (partial select) is a
  `MissingAttribute` error. It is never treated as `NULL`.

### 4.3 Types

| Type       | Policy value            | Bound as          | Raw value accepted at runtime                           |
|------------|-------------------------|-------------------|---------------------------------------------------------|
| `int`      | `int`                   | int               | `int`, or a string matching `-?\d+`                     |
| `string`   | `string`                | string            | `string`                                                |
| `bool`     | `bool`                  | `0` / `1`         | `bool`, `0`, `1`, `'0'`, `'1'`                          |
| `datetime` | `DateTimeInterface`     | UTC `Y-m-d H:i:s` | `Y-m-d H:i:s[.u]` string read as UTC, or `DateTimeInterface` |

Anything else is `AttributeTypeMismatch`. Runtime evaluation reads **raw**
attributes (no casts, no accessors).

String equality is exact (case- and trailing-space-sensitive) on every engine.
On MySQL/MariaDB, whose default collations are case-insensitive, the compiler
emits `(col = ? AND CAST(col AS BINARY) = CAST(? AS BINARY))`: the first
conjunct can use an index, the second makes the match exact. This assumes the
column and the connection share a character set (the framework default,
`utf8mb4`). Ordered string comparison is not offered. Datetime parity assumes
the application stores UTC.

SQLite stores datetimes as text, where `12:00:00` and `12:00:00.000` would be
different values. There the compiler compares
`strftime('%Y-%m-%d %H:%M:%f', col)` instead, so the two are the same instant as
they are everywhere else. `strftime` offers millisecond precision: on SQLite,
values that differ only below a millisecond are not distinguished. A value
SQLite cannot parse is treated like `NULL` by the compiler and is an
`AttributeTypeMismatch` at runtime.

### 4.4 The composed read predicate

With `H` = conjunction of mandatory predicates, `P` = disjunction of privileged,
`D` = disjunction of deny, `A` = disjunction of grant, `T` = terminal as a
constant:

```
visible  =  H AND ( P OR ( NOT D AND ( A OR T ) ) )
```

Privileged grants are never flattened into ordinary grants, and an explicit
terminal `Allow` bypasses neither `D` nor `H`. Constants fold: a viewer-only
rule such as "is staff" becomes `always()`/`never()` at resolve time and
disappears from the SQL.

**Resolve, then interpret.** `PolicyResolver` turns `(RuleSet, context)` into a
`ResolvedReadPolicy` holding the per-stage predicates. Rule closures run here,
once per operation, and this is where context errors surface. Resolution is
stage-ordered: if a stage folds to a decisive constant, later stages are not
resolved. Both interpreters consume the same `ResolvedReadPolicy`:

- `Sql\PredicateCompiler` applies it to a query inside one nested group.
- `Runtime\PredicateEvaluator` evaluates it against `RowSnapshot`s, reducing
  stage by stage exactly as §2 describes.

`Exists` at runtime needs relationship facts. They are **batch-prepared**:
`RuntimeEvaluation::prepare($snapshots)` issues one bounded query per `Exists`
node per batch (inner rows by join key, chunked), and the inner predicate is
then evaluated in PHP. Evaluating an `Exists` node that was not prepared is an
error; it never lazy-loads per row. The number of queries depends on the policy
shape, not on the number of rows.

## 5. Protected query boundary **[slice 1]**

Entry points:

```php
Record::privacyQuery($context)                    // trait HasPrivacyPolicy
Privacy::query(Record::class, $context)           // explicit registry
```

`privacyQuery` was chosen so it cannot collide with an application scope such as
`visibleTo`. Passing `null` throws `MissingContext`.

The model must use the `HasPrivacyPolicy` trait either way, because the trait
carries the guards of §5.2; the registry only changes where the *policy* comes
from (`Privacy::register()` wins over a static `privacyPolicy()` method). A
method declared on the model class itself silently wins over a trait method, so
a model that overrides any guarded method is refused with
`UnsupportedProtectedOperation` rather than queried with its guards disabled.

Both return `Query\ProtectedBuilder<TModel>`. It **composes** a native Eloquent
builder and never exposes it: there is no `__call`, no macro forwarding, no
`toBase()`, no `getQuery()`. The executed query is always

```
<model global scopes, e.g. soft deletes>  AND  ( privacy )  AND  ( caller )
```

with the caller's conditions confined to their own nested group, so a caller
`orWhere` cannot widen the result.

### 5.1 Supported surface

| Area        | Methods                                                                                     |
|-------------|---------------------------------------------------------------------------------------------|
| filters     | `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, nested `where(Closure)` receiving a `FilterGroup` with the same methods |
| shape       | `select([...])` (primary key always added), `orderBy`, `limit`, `offset`                   |
| read        | `get`, `first`, `find`, `findOrFail`, `firstOrFail`                                        |
| aggregates  | `exists`, `count`, `sum($column)`                                                          |
| pagination  | `paginate($perPage, $page)` with `1 ≤ $perPage ≤ maxPerPage` (default 200)                 |
| relations   | `with([...])` for one-level `BelongsTo` / `HasMany` whose related model has a policy       |

Filter, `select`, `orderBy` and `sum` columns must be plain identifiers
(optionally `table.column` on the root table; any other table is rejected); operators come from a fixed list; values must be scalar, `null`,
`DateTimeInterface`, or a list of those. Expressions, closures-as-values,
builders and subqueries are rejected.

### 5.2 Returned models

Models hydrated by a protected builder carry their context on the instance (a
plain PHP property, never an attribute, never static). On such an instance:

| Path                                                     | Behaviour                                                       |
|----------------------------------------------------------|-----------------------------------------------------------------|
| lazy property access `$m->parent`, `$m->children` (`BelongsTo`, `HasMany`) | loaded **through the related model's policy** under the same context; results are themselves protected |
| serialisation (`toArray`, `toJson`)                      | serialises what is loaded; an accessor that lazy-loads goes through the row above |
| `$m->privacyRelation('children')`                        | a `ProtectedBuilder` rooted at the related model, constrained by the foreign key |
| calling the relation method directly, `$m->children()`   | **rejected** — it would hand out a raw builder whose `orWhere` escapes both constraints |
| other relation types, or a related model without a policy | **rejected**                                                   |
| `refresh`, `fresh`, `load*`, `loadMissing`               | **rejected** — re-query through a protected builder             |
| `save`, `saveQuietly`, `update`, `push`, `delete`, `forceDelete`, `increment`/`decrement`, `touch`, `restore` | **rejected** — a read never authorises a write; use an action |

Rejected means `UnsupportedProtectedOperation`.

### 5.3 Not supported

Raw joins and subqueries, unions, raw SQL fragments, polymorphic and
many-to-many paths, pivots, `withTrashed`/scope removal, bulk `update`/`delete`/
`upsert`/`insert` through the protected builder, cursors/lazy collections,
`groupBy`/`having`, nested eager loads. Each is either absent from the API or
throws. Queue-restored models are ordinary models: jobs must re-authorise from
identifiers, not from a serialised decision.

A caller filter may name any column of the root table. Filtering is confined to
rows the viewer may already see, but it can still act as an oracle on a column
the application never outputs; which columns are disclosed is output shaping,
which stays with the application.

**[staged]**, in the order they are expected to be needed: cursor pagination;
relations that carry their own constraints; a way for the application to tell
"not visible" from "visible but this action is not permitted" without the
package disclosing either to the client; static-analysis checks that flag
ordinary queries on adopted models inside adopted paths; model-wide strict
adoption.

## 6. Actions **[slice 1]**

A successful read authorises nothing else. Writes go through
`Action\ActionExecutor::execute(Action $action, PrivacyContext $context)`, whose
sequence cannot be reordered by the action:

1. open a transaction (a savepoint if one is already open);
2. take the action's **anchor locks** in deterministic order (table, then key);
3. load the authoritative pre-state — or the creation parent — with a locking
   read, as a fresh, unprotected instance. A model the caller already holds is
   never trusted, whatever context it was loaded under;
4. resolve action facts with **locking reads** inside the transaction;
5. **authorise**: reduce the action's own `RuleSet` (§2) over an `ActionInput`
   of context, pre-state snapshot, parent snapshot and proposed changes;
6. **validate** (application hook);
7. **persist** (application hook, given the locked instance);
8. commit; then run after-commit callbacks; return an `ActionResult`.

Action rules are `Policy\ActionRule`s — `decide(PrivacyContext, ActionInput): Decision`
— and may be arbitrary pure PHP. They are runtime-only and are never used for
collection filtering. A `PredicateRule` can be reused as an action rule; it is
then evaluated against the persisted pre-state snapshot.

Ownership and parent changes are authorised explicitly: rules see the persisted
pre-state and the proposed changes separately, so a dirty owner field cannot
authorise itself. Reparenting requires the action to name the proposed parent,
which is loaded and locked like the pre-state.

**Denial.** `Deny`, or any error, rolls back and throws (`ActionDenied` for a
decision). No domain data is changed and no after-commit callback runs. The
optional denial-audit hook runs after the rollback, outside the transaction, and
receives identifiers only.

**Disclosure.** `ActionResult` always carries a `Receipt` (action, target key,
optional version). `ActionResult::readable()` re-queries the target through the
protected read path under the same context and returns `null` when the caller
may write but not read. The executor never returns the hydrated persisted model.

**Nested transactions.** Inside an open transaction the executor uses a
savepoint; a denial rolls back to it and throws; locks are held until the
outermost commit; after-commit callbacks follow the framework's semantics and
run at the outermost commit.

### 6.1 Revocation protocol — what is and is not claimed

Opening a transaction, or locking the target row, does not stop a concurrent
grant revocation. The protocol is a pair:

- every action names the **anchor rows** its authorisation depends on (normally
  the resource-boundary parent row), and the executor locks them `FOR UPDATE`
  before reading any grant;
- every **participating grant writer** wraps its mutation in
  `Privacy::withAnchors([...], fn)` which takes the same locks in the same order.

Because grant facts are read with locking reads *after* the anchor lock, an
action either sees the committed revocation and is denied, or holds the anchor
and makes the revoker wait until the action has committed. Locking reads are
required rather than optional: under `REPEATABLE READ` a plain read may be served
from a snapshot taken before the lock.

Claimed, on MySQL and MariaDB with InnoDB, for participating writers only. Not
claimed: writers that do not take the anchor; bulk or raw grant mutations;
SQLite; anything already authorised and in flight (issued URLs, queued work,
after-commit side effects). External I/O never happens while anchor locks are
held.

## 7. Extension seams **[slice 1: interfaces only]**

`Policy\GroupExecutor` evaluates the rules of one stage and returns all outcomes;
the only implementation is synchronous. `Context\FactProvider` resolves facts for
an operation. They exist so concurrency can be added later without changing the
semantics above. No scheduler, fibre, process pool, shared cache or Redis
dependency is part of this package.
