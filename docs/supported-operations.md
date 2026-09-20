# Supported operations

The behaviour matrix for `Query\ProtectedBuilder`, `Query\FilterGroup`, models
returned by a protected builder, `Query\ProtectedCollection`, and actions.
Behaviour is read from `src/`, not from `docs/contract.md` alone; the "test"
column cites a proving test from `tests/Kernel`, `tests/Action`, or
`tests/Concurrency` when one exists there, and is left as `—` otherwise. A `—`
means only that no proof was found in those three suites during this review —
not that the behaviour is unproven anywhere, since `tests/Policy` and
`tests/Query` also exercise this code and were out of scope for this pass.

## Discrepancies found

None. Every behaviour checked below in `src/` matches what `docs/contract.md`
describes; no correction to either file was needed.

## `ProtectedBuilder` — caller filters

| Operation | Behaviour | Test |
|---|---|---|
| `where($col, $op, $val)` / `where($col, $val)` | Supported | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `orWhere(...)` | Supported; confined to the caller's own nested group | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `where(Closure)` — nested group receiving a `FilterGroup` | Supported | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `FilterGroup::orWhereNull($col)` | Supported | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `whereIn` / `whereNotIn` | Supported (per source; `FilterGroup::whereIn()`/`whereNotIn()`) | — |
| `whereNull` / `whereNotNull` (top-level, not inside a nested group) | Supported | — |
| `whereBetween` | Supported | — |
| comparing a column against `null` via `where()` | Rejected: `UnsupportedProtectedOperation` (use `whereNull()`/`whereNotNull()`) | — |
| unsupported operator string | Rejected: `InvalidIdentifier` | — |
| filter value that is a builder, closure, or other non-scalar/non-`DateTimeInterface` | Rejected: `UnsupportedProtectedOperation` | — |
| column not a plain identifier, or qualified with a table other than the root table | Rejected: `InvalidIdentifier` | — |

## `ProtectedBuilder` — shape and execution

| Operation | Behaviour | Test |
|---|---|---|
| `select([...])` | Supported; the primary key is always added | — |
| `orderBy($col, $dir)` | Supported | `tests/Kernel/KernelSmokeTest::test_sql_and_runtime_agree_and_match_the_expected_set` (compares an `orderBy('id')` result against the runtime interpreter) |
| `orderBy` with a direction other than `asc`/`desc` | Rejected: `InvalidIdentifier` | — |
| `limit(n)` / `offset(n)` | Supported | — |
| `get()` | Supported | `tests/Kernel/KernelSmokeTest::test_sql_and_runtime_agree_and_match_the_expected_set` |
| `first()` / `firstOrFail()` | Supported | — |
| `find($id)` | Supported; returns `null` for a row that exists but is not visible | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` (`find(8)` is `null`) |
| `findOrFail($id)` | Supported | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `count()` | Supported | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `exists()` | Supported (per source) | — |
| `sum($column)` | Supported (per source) | — |
| `paginate($perPage, $page)` | Supported; `1 ≤ $perPage ≤ 200` | `tests/Kernel/KernelSmokeTest::test_a_caller_or_cannot_widen_the_result` |
| `paginate()` with `$perPage` outside `1..200` | Rejected: `UnsupportedProtectedOperation` | — |
| `paginate()` combined with `limit()`/`offset()` | Rejected: `UnsupportedProtectedOperation` | — |
| `with([...])` for a one-level `BelongsTo`/`HasMany` to a model with a policy | Supported | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `with()` with a dotted or `:`-constrained relation name | Rejected: `UnsupportedProtectedOperation` | — |
| `with()` for a relation of any other type, or to a model with no policy | Rejected: `UnsupportedProtectedOperation` | — |
| `with()` for a relation that adds its own query constraints | Rejected: `UnsupportedProtectedOperation` | — |
| a model without the `HasPrivacyPolicy` trait | Rejected: `UnsupportedProtectedOperation` | — |
| a model that overrides a guarded method (`GuardAudit`) | Rejected: `UnsupportedProtectedOperation` | — |
| `null` context passed to `privacyQuery()` / `Privacy::query()` | Rejected: `MissingContext` | `tests/Kernel/KernelSmokeTest::test_missing_context_and_missing_facts_are_errors` |
| raw joins, subqueries, unions, `groupBy`/`having`, cursors/lazy collections, `withTrashed`/scope removal, bulk `update`/`delete`/`upsert`/`insert`, polymorphic and many-to-many relation paths, nested eager loads, pivots | Not part of the API — no such method exists on `ProtectedBuilder` | — |

## Returned models

Models hydrated by a protected builder carry the context that produced them.

| Path | Behaviour | Test |
|---|---|---|
| lazy `$m->relation` (`BelongsTo`/`HasMany` to a model with a policy) | Supported; loaded through the related model's own policy under the same context | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `$m->privacyRelation('name')` | Supported: returns a `ProtectedBuilder` constrained by the foreign key (per source; exercised indirectly by every lazy-load test, never called directly in these three suites) | — |
| calling a relation method directly, `$m->children()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| a relation of a type other than `BelongsTo`/`HasMany`, or to a model with no policy | Rejected: `UnsupportedProtectedOperation` (per source) | — |
| `toArray()` / `toJson()` | Supported; serialises what is already loaded (per source: no override exists for these) | — |
| `refresh()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `fresh()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `load(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `loadMissing(...)` / `loadAggregate(...)` / `loadMorph(...)` / `loadMorphAggregate(...)` | Rejected: `UnsupportedProtectedOperation` (per source; same guard as `load()`) | — |
| `save()` / `saveQuietly()` / `update(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` (`update`, `saveQuietly`); `tests/Action/ActionExecutorTest::test_a_model_fetched_under_one_context_is_useless_under_another` (`save`) |
| `delete()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `forceDelete()` / `restore()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` |
| `increment()` / `decrement()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` (`increment`) |
| `touch()` | Rejected: `UnsupportedProtectedOperation` (per source; funnels into the same guarded `save()`) | — |
| an instance loaded the ordinary way (`Model::query()`, `Model::find()`) | Unchanged: none of the above guards apply | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` (`Note::query()->findOrFail(1)->folder`) |

## `ProtectedCollection`

| Operation | Behaviour | Test |
|---|---|---|
| `load(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest::test_relations_stay_protected_and_escapes_are_rejected` (`$folder->notes->load('folder')`) |
| `loadMissing(...)` / `loadAggregate(...)` / `loadMorph(...)` / `loadMorphCount(...)` | Rejected: `UnsupportedProtectedOperation` (per source; same guard as `load()`) | — |
| `fresh(...)` | Rejected: `UnsupportedProtectedOperation` (per source) | — |
| `toQuery()` | Rejected: `UnsupportedProtectedOperation` (per source) | — |
| `withRelationshipAutoloading()` | Rejected: `UnsupportedProtectedOperation` (per source) | — |
| every other `Illuminate\Database\Eloquent\Collection` method (`filter`, `map`, `modelKeys`, ...) | Supported; unmodified from Eloquent | `tests/Kernel/KernelSmokeTest` (`modelKeys()` throughout) |

## Actions

| Operation | Behaviour | Test |
|---|---|---|
| `ActionExecutor::execute()` sequence (transaction → anchors → pre-state → facts → authorise → validate → persist → commit → after-commit) | Supported, in this fixed order | `tests/Action/ActionExecutorTest::test_facts_are_resolved_inside_the_transaction_after_the_locks` |
| anchor locks taken in `(table, key)` order, de-duplicated | Supported | `tests/Action/AnchorLockingTest::test_anchors_are_locked_in_sorted_order_whatever_order_they_were_declared_in`, `::test_the_order_is_total_and_de_duplicated` |
| anchor lock is a real `FOR UPDATE` on a real engine | Supported | `tests/Action/AnchorLockingTest::test_the_lock_is_a_real_for_update_on_a_real_engine` |
| a named anchor row that does not exist | Rejected: `MissingLockedRow` | `tests/Action/AnchorLockingTest::test_a_missing_anchor_row_fails_the_action` |
| `Privacy::withAnchors()` — a participating grant writer takes the same locks in the same order | Supported | `tests/Action/AnchorLockingTest::test_a_participating_grant_writer_takes_the_same_locks_in_the_same_order` |
| `Privacy::withAnchors()` whose anchor row is missing | Rejected: `MissingLockedRow`; the writer's closure never runs | `tests/Action/AnchorLockingTest::test_a_grant_writer_whose_anchor_is_missing_does_not_run` |
| a caller-held / caller-mutated instance passed to an action | Ignored beyond its key: the executor always re-loads and locks its own copy | `tests/Action/ActionExecutorTest::test_an_instance_the_caller_mutated_contributes_nothing_but_its_key` |
| a model fetched under one context, then acted on under another | The fetch context is irrelevant; authorisation is re-evaluated | `tests/Action/ActionExecutorTest::test_a_model_fetched_under_one_context_is_useless_under_another` |
| a proposed change to the owner/foreign-key column | Never authorises itself; rules see persisted pre-state and proposed changes separately | `tests/Action/ActionExecutorTest::test_a_dirty_owner_field_cannot_authorise_itself`, `tests/Action/ActionAuthorisationTest::test_a_predicate_rule_sees_the_persisted_pre_state_not_the_proposed_changes` |
| reparenting (a change to the parent foreign key) | The proposed parent is loaded and locked, and must itself be authorised; loaded only when the changes touch the foreign key | `tests/Action/ActionExecutorTest::test_reparenting_needs_the_proposed_parent_authorised`, `::test_the_proposed_parent_is_loaded_only_when_the_changes_touch_the_foreign_key` |
| creation (`targetKey() === null`) | Authorised through the creation parent only; a reused `PredicateRule` cannot authorise it (no persisted pre-state) | `tests/Action/ActionExecutorTest::test_a_creation_is_authorised_through_the_creation_parent`, `tests/Action/ActionAuthorisationTest::test_a_predicate_rule_cannot_authorise_a_creation` |
| a `PredicateRule` reused as an action rule that reads a relationship (`Exists`/`ViaParent`) | Rejected: `UnsupportedProtectedOperation` | `tests/Action/ActionAuthorisationTest::test_a_predicate_rule_that_reads_a_relationship_is_rejected` |
| action facts resolved via `ActionFactProvider` | Read under lock (`LockingReads`), after the anchor lock and the pre-state load | `tests/Action/ActionExecutorTest::test_facts_are_resolved_inside_the_transaction_after_the_locks`, `tests/Action/AnchorLockingTest::test_the_lock_is_a_real_for_update_on_a_real_engine` |
| an anonymous viewer against a rule set without `allowAnonymous()` | Denied before any lock is taken and before any rule runs | `tests/Action/ActionAuthorisationTest::test_an_anonymous_viewer_is_denied_before_any_rule_runs` |
| an anonymous viewer against a rule set with `allowAnonymous()` | Rules run normally | `tests/Action/ActionAuthorisationTest::test_a_rule_set_that_allows_anonymous_viewers_runs_its_rules` |
| a stage outcome the stage does not permit | Rejected: `InvalidOutcome`, wrapped in `StageEvaluationFailed`; rolls back | `tests/Action/ActionAuthorisationTest::test_an_outcome_a_stage_does_not_permit_fails_the_stage_and_rolls_back` |
| a missing fact read inside a rule | `MissingFact`, wrapped in `StageEvaluationFailed`; rolls back, not audited as a denial | `tests/Action/ActionAuthorisationTest::test_a_missing_fact_fails_the_stage_and_rolls_back`, `::test_an_error_is_not_reported_to_the_denial_auditor` |
| a `Deny` decision (or the default terminal) | Rejected: `ActionDenied`; rolls back, changes nothing, no after-commit callback runs | `tests/Action/ActionExecutorTest::test_a_denial_changes_nothing_audits_once_and_runs_no_after_commit_callback` |
| a denial | Reported to the optional `DenialAuditor`, after rollback, identifiers only | `tests/Action/ActionExecutorTest::test_a_denial_changes_nothing_audits_once_and_runs_no_after_commit_callback` |
| `validate()` throwing | Rolls the action back; no after-commit callback runs | `tests/Action/ActionExecutorTest::test_a_validation_failure_rolls_the_action_back` |
| a model with a read policy but no policy for the attempted action | Rejected: `PolicyNotRegistered`; a read policy never authorises a write | `tests/Action/ActionExecutorTest::test_a_read_policy_never_authorises_a_write` |
| `null` context passed to `execute()` | Rejected: `MissingContext` | `tests/Action/ActionExecutorTest::test_a_null_context_is_an_error` |
| an action nested inside an open transaction | Runs in a savepoint; a denial rolls back to the savepoint, leaving the outer transaction usable | `tests/Action/NestedTransactionTest::test_a_denial_rolls_back_to_the_savepoint_and_leaves_the_outer_transaction_usable` |
| an action nested inside another action | Its own savepoint | `tests/Action/NestedTransactionTest::test_an_action_nested_in_an_action_uses_its_own_savepoint` |
| `afterCommit()` callback, nested transaction | Runs once, at the outermost commit; never if the outer transaction rolls back | `tests/Action/NestedTransactionTest::test_after_commit_work_waits_for_the_outermost_commit`, `::test_after_commit_work_never_runs_when_the_outer_transaction_rolls_back` |
| `ActionResult` / `Receipt` | Never carries the persisted model, only identifiers | `tests/Action/ActionExecutorTest::test_the_result_never_carries_the_persisted_model` |
| `ActionResult::readable()` | Re-queries through the protected read path under the same context; `null` when the caller may write but not read, or when the model has no read policy | `tests/Action/ActionResultTest::test_readable_returns_the_row_through_the_protected_read_path`, `::test_readable_is_null_for_a_caller_that_may_write_but_not_read`, `::test_readable_is_null_when_the_model_has_no_read_policy` |
| `ActionResult::readable()` called more than once | Re-queries every time; does not hold the row | `tests/Action/ActionResultTest::test_readable_re_queries_every_time_rather_than_holding_the_row` |
| revocation protocol: a revoker that takes the anchor before the action | The action waits for the anchor, then reads the grant already revoked; denied | `tests/Concurrency/RevocationRaceTest::test_a_revoker_that_takes_the_anchor_first_denies_the_action` |
| revocation protocol: an action that takes the anchor before the revoker | The revoker waits for the anchor until the action commits; the action's write and the later revocation both land | `tests/Concurrency/RevocationRaceTest::test_an_action_that_takes_the_anchor_first_makes_the_revoker_wait` |
| revocation protocol: a grant writer that does not take the anchor | Not covered — it can interleave mid-action and change the answer the action reads | `tests/Concurrency/RevocationRaceTest::test_a_grant_writer_that_takes_no_anchor_is_not_covered` |
| a plain (non-locking) read of a fact inside a transaction, vs. `LockingReads` | A plain read can be stale (`REPEATABLE READ` snapshot); `LockingReads` is always current, including inside a savepoint | `tests/Concurrency/SnapshotStalenessTest::test_a_plain_read_can_be_stale_where_a_locking_read_is_current` |
