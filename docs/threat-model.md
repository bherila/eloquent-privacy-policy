# Threat model

This describes what the protected API (`docs/contract.md` §5 and §6) defends
against, for whom, and what it explicitly leaves to the application. It is not
a claim of completeness, security review, or production readiness — see the
status section of `README.md`.

## Assets

- Rows of models that use `HasPrivacyPolicy` and are queried through
  `privacyQuery()` / `Privacy::query()` — the data the read policy decides
  visibility for.
- Writes to those models made through `Action\ActionExecutor` — the data an
  action's rule set decides authorisation for.
- Grant/membership rows that a policy's rules read as facts (via `Context\Facts`,
  an `ActionFactProvider`, or an `Exists`/`ViaParent` predicate) — these are not
  a separate asset class in the package's eyes, but they are frequently what a
  rule's correctness depends on, and the revocation protocol (contract §6.1)
  exists specifically to protect their consistency during a write.

## Trust boundaries

**Trusted** (the package assumes these are correct and cannot itself verify
them):

- Policy code: `ModelPolicy`, `RuleSet`, `PredicateRule`/`ActionRule`
  implementations. A rule that is wrong, or that reads the wrong fact, produces
  a wrong decision the package will faithfully enforce.
- Context adapters: whatever application code builds a `PrivacyContext`. The
  package trusts the `Viewer`, `Capacity`, `ResourceScope`, and `Facts` it is
  given; it has no way to check that the adapter authenticated the caller or
  computed the scope correctly.
- Action classes and their `ActionFactProvider`s: an `Action` declares its own
  anchors, parent link, and facts. If an action does not anchor on the row a
  grant hangs off, the revocation protocol (§6.1) simply does not apply to it —
  the package cannot infer the right anchor for it.
- Anything holding database credentials. Per contract §1, application PHP with
  DB access can always run an unprotected query; the protected API is a
  discipline the application chooses to route through, not a wall around the
  database.

**Untrusted** (the package validates or constrains this itself):

- Client input that reaches caller filters, `find()`/`findOrFail()` ids, or an
  action's proposed `changes()`. Filters are validated (plain identifiers, a
  fixed operator list, scalar/`DateTimeInterface`/list values only) and
  confined to their own nested query group (§5); proposed changes are only
  ever compared against the persisted pre-state, never trusted as authority
  (§6).

## What the protected API defends against

Each of the following has a proving test cited in `docs/supported-operations.md`
or in the suites named:

- **Caller OR-widening.** A caller's `orWhere` cannot escape the privacy
  predicate, because caller filters are confined to their own nested group
  (`tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result`).
- **A dirty owner field authorising itself.** Action rules see the persisted
  pre-state and the proposed changes as two separate values; setting the owner
  column in the proposed changes does not make the row look owned
  (`tests/Action/ActionExecutorTest::test_a_dirty_owner_field_cannot_authorise_itself`).
- **Stale instances across contexts.** A model instance fetched under one
  context (or mutated in memory and never saved) contributes nothing to an
  action but its key; the executor always re-loads and locks its own copy
  (`tests/Action/ActionExecutorTest::test_an_instance_the_caller_mutated_contributes_nothing_but_its_key`,
  `::test_a_model_fetched_under_one_context_is_useless_under_another`).
- **Lazy-load escape.** A relation is only reachable through
  `getRelationValue()`/`privacyRelation()`, which re-queries under the same
  context and the related model's own policy; calling the relation method
  directly is rejected
  (`tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected`).
- **NULL / partial-select confusion.** A column absent from a snapshot
  (partial `select()`) is `MissingAttribute`, never treated as `NULL`; a `NULL`
  column makes a comparison false and its negation true, both in SQL and in
  the runtime evaluator
  (`tests/Kernel/KernelSmokeTest::test_a_partial_snapshot_is_an_error_not_a_null`).
- **Collation-insensitive string matches.** On MySQL/MariaDB the compiler adds
  a `CAST(... AS BINARY)` conjunct so a case-insensitive column collation
  cannot make an unequal string match (contract §4.3;
  `benchmarks/README.md` confirms the guard's absence from SQL that has no
  string comparison, which is the flip side of the same mechanism — no
  proving unit test for the guard itself was found in `tests/Kernel`,
  `tests/Action`, or `tests/Concurrency`).
- **Revoke-vs-write for participating writers.** An action's anchor lock and a
  participating grant writer's `Privacy::withAnchors()` lock are the same rows
  in the same order, so a write is either denied by a revocation that
  committed first, or the revocation waits for the write to commit
  (`tests/Concurrency/RevocationRaceTest`, both interleavings).

## What it explicitly does not defend against

- **Ordinary Eloquent queries and raw SQL.** `Model::query()`, `Model::find()`,
  and any query issued outside `privacyQuery()`/`Privacy::query()` are
  completely unaffected (contract §1;
  `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected`,
  final assertion). Adopting this package on one model or one path does
  nothing for every other path to the same table.
- **Overridden guard methods vs. other interference.** A model that redeclares
  a guarded method (`save`, `delete`, `getRelationValue`, ...) on the class
  itself is refused outright (`GuardAudit`), but this only catches the guarded
  method list itself. A different trait, a service that mutates attributes
  directly via `setRawAttributes()`, or reflection-based tampering are not
  something `GuardAudit` or the trait's guards can see.
- **`syncOriginal()`-style tampering of in-memory state.** For actions this is
  irrelevant: the executor always re-reads the authoritative pre-state from
  the database under lock, never from the instance the caller holds. For
  anything else — a read-time decision, a cached instance, application code
  that trusts `$model->getOriginal()` — it matters, and the package has no
  opinion on it because nothing in the protected *read* path claims to defend
  against in-memory mutation of an instance you already have.
- **Filter-as-oracle on undisclosed columns.** A caller filter may name any
  column of the root table (contract §5.3); it is confined to rows the viewer
  may already see, but a filter on a column the application never displays can
  still be used to infer that column's value one comparison at a time. This is
  a disclosure question for the application's output shaping, not something
  the filter validator can resolve.
- **Output shaping / redaction.** The package decides which *rows* are
  visible, never which *columns* of a visible row should be hidden from a
  particular viewer. `select()` narrows what is fetched, but choosing which
  columns are safe to expose is an application concern.
- **Non-participating grant writers.** `Privacy::withAnchors()` is opt-in.
  Contract §6.1 and
  `tests/Concurrency/RevocationRaceTest::test_a_grant_writer_that_takes_no_anchor_is_not_covered`
  demonstrate exactly what "not covered" looks like: such a writer can commit
  in the middle of an action's transaction and change the answer the action
  reads, in either direction.
- **Bulk or raw grant mutations.** An `UPDATE ... WHERE` or a raw statement
  against a grant table, run outside `Privacy::withAnchors()`, is a
  non-participating write like any other.
- **SQLite locking.** The revocation protocol is claimed only for MySQL/MariaDB
  with InnoDB (contract §6.1). SQLite's locking model does not give the
  concurrent-transaction guarantees the protocol depends on; the concurrency
  suite itself only runs against a real engine
  (`tests/Concurrency/ConcurrencyTestCase::setUp` calls `requiresRealEngine()`).
- **Already-issued URLs, queued work, or after-commit side effects.** A
  revocation cannot recall a signed URL already handed out, a job already
  queued from a prior read, or any side effect that already ran. The protocol
  covers the write inside the transaction, nothing issued before or after it.
- **Queue-restored models.** A model deserialised by a queued job is an
  ordinary model with no privacy context; contract §5.3 is explicit that jobs
  must re-authorise from identifiers, never from a serialised decision.
- **Timing side channels.** Nothing in the package equalises the time taken by
  an allow vs. a deny, a mandatory-stage short-circuit vs. a full stage
  evaluation, or a `find()` that matches zero rows vs. one it is not allowed to
  see. A sufficiently patient attacker with query-timing access is out of
  scope.
- **Denial-of-service via expensive policies.** A policy with many `Exists`
  nodes, deep `ViaParent` chains, or large `whereIn` lists costs what it costs;
  the package does not impose complexity limits on policy authors.

## Assumptions

- **UTC datetimes.** `PrivacyContext::$now` is normalised to UTC
  (`Context\PrivacyContext::__construct`), and `ColType::Datetime` reads and
  binds in UTC. An application storing local time in a `datetime` column will
  get wrong comparisons, silently.
- **Column charset equals the connection charset.** The MySQL/MariaDB exact-
  string-match guard (contract §4.3) assumes the column and the connection
  share a character set — the framework default, `utf8mb4`. A column on a
  different charset is not accounted for.
- **Declared column types are true.** `Col::int/string/bool/datetime($name)` is
  policy-author-declared, not read from the schema. If a column is actually a
  different SQL type than declared, `AttributeTypeMismatch` on obviously wrong
  raw values, but a value that happens to coerce (e.g. a string that looks like
  an int) will not be caught.
- **InnoDB.** The revocation protocol's locking guarantees (contract §6.1) are
  stated for MySQL/MariaDB with InnoDB specifically; a table on a different
  storage engine is not covered even on those two database products.

## Residual risks, ranked

Highest first, by the reviewer's own judgement of how easily an application
could get this wrong without noticing:

1. **A policy author writes a rule that reads the wrong fact, or forgets a
   stage.** The package enforces the five-stage reduction correctly, but
   nothing checks that the *policy* means what the author intended. This is
   the single largest source of real-world risk with any policy-as-data
   system, and it is entirely outside what code review of this package can
   catch.
2. **An action with no anchors, or the wrong anchor.** Contract §6.1 makes the
   revocation protocol opt-in per action; an action that authorises off a
   grant but never anchors on the row the grant hangs off gets none of the
   concurrency guarantee, with no error or warning — it simply behaves like
   any other unprotected concurrent write.
3. **A non-participating grant writer added later.** A grant table with one
   writer that correctly uses `Privacy::withAnchors()` can silently gain a
   second writer (a new admin tool, a bulk import, a raw migration) that does
   not. Nothing enforces "every writer of this table participates."
4. **Filter-as-oracle on a column the application does not intend to expose.**
   Low severity per row, but systematic: any protected model with a filterable
   column the UI never renders is technically an oracle for that column's
   distribution, to a viewer who can already see some rows.
5. **SQLite masking the absence of real locking in local development.** The
   whole suite (except `tests/Concurrency`) runs against SQLite by default; an
   application developing and testing exclusively on SQLite will never
   observe that its actions have no revocation protection, because SQLite
   never exercises `FOR UPDATE` semantics in the same way.
6. **Timing and existence side channels.** Present but the least likely of
   these to be the practical way a real deployment's privacy boundary is
   crossed, absent an unusually motivated and well-positioned attacker.
