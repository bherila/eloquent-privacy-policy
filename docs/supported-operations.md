# Supported operations

The behaviour matrix for `Query\ProtectedBuilder`, `Query\FilterGroup`, models
returned by a protected builder, `Query\ProtectedCollection`, actions, and the
predicate/policy layer they are built on. Behaviour is read from `src/`, not
from `docs/contract.md` alone; the "test" column cites a proving test from
`tests/Kernel`, `tests/Action`, `tests/Concurrency`, `tests/Policy`, or
`tests/Query` (with fixtures in `tests/Fixtures`) when one exists. A `—` means
no proof was found in any of those suites during this review, reading the test
bodies rather than just their names — not that the behaviour is unproven
anywhere a future suite might cover it.

## Discrepancies found

None. Every behaviour checked below in `src/`, including the six kernel
changes this review specifically checked against `docs/contract.md` §4.1,
§4.4, §5.1, §5.2 and §4.3 (integer-only `Exists` correlation, the qualified-
column form for `select()`/`orderBy()`/`sum()`, the `touch()` guard, an
unprepared `Exists` raising `MissingFact`, the `paginate()` page-overflow
guard, and SQLite's millisecond datetime precision), matches what
`docs/contract.md` describes. This is true at the time of writing; it is not
re-checked automatically and can drift as either file changes.

## `ProtectedBuilder` — caller filters

| Operation | Behaviour | Test |
|---|---|---|
| `where($col, $op, $val)` / `where($col, $val)` | Supported | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `orWhere(...)` | Supported; confined to the caller's own nested group | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `where(Closure)` — nested group receiving a `FilterGroup` | Supported | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `FilterGroup::orWhereNull($col)` | Supported | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `whereIn` / `whereNotIn` | Supported (per source; `FilterGroup::whereIn()`/`whereNotIn()`) | `tests/Query/FilterValidationTest.php::test_a_filter_column_may_name_the_root_table_explicitly` (`whereIn` narrows correctly), `tests/Query/ProtectedBuilderTest.php::test_no_arrangement_of_caller_filters_can_widen_the_result` (`whereIn`/`whereNotIn` stay bounded) |
| `whereNull` / `whereNotNull` (top-level, not inside a nested group) | Supported | `tests/Query/FilterValidationTest.php::test_null_may_only_be_expressed_with_where_null` |
| `whereBetween` | Supported | `tests/Query/ProtectedBuilderTest.php::test_no_arrangement_of_caller_filters_can_widen_the_result` (`between everything`/`between reversed`) |
| comparing a column against `null` via `where()` | Rejected: `UnsupportedProtectedOperation` (use `whereNull()`/`whereNotNull()`) | `tests/Query/FilterValidationTest.php::test_null_may_only_be_expressed_with_where_null` |
| unsupported operator string | Rejected: `InvalidIdentifier` | `tests/Query/FilterValidationTest.php::test_only_the_fixed_operator_list_is_accepted` |
| filter value that is a builder, closure, or other non-scalar/non-`DateTimeInterface` | Rejected: `UnsupportedProtectedOperation` | `tests/Query/FilterValidationTest.php::test_non_scalar_values_are_rejected` |
| column not a plain identifier, or qualified with a table other than the root table | Rejected: `InvalidIdentifier` | `tests/Query/FilterValidationTest.php::test_only_plain_columns_of_the_root_table_are_accepted` |

## `ProtectedBuilder` — shape and execution

| Operation | Behaviour | Test |
|---|---|---|
| `select([...])` | Supported; the primary key is always added; a column may be qualified with the root table, exactly like a filter column | `tests/Query/ProtectedBuilderTest.php::test_select_always_keeps_the_primary_key`, `tests/Query/FilterValidationTest.php::test_shape_columns_accept_the_root_table_and_nothing_else` |
| `orderBy($col, $dir)` | Supported; a column may be qualified with the root table, exactly like a filter column | `tests/Kernel/KernelSmokeTest.php::test_sql_and_runtime_agree_and_match_the_expected_set` (compares an `orderBy('id')` result against the runtime interpreter), `tests/Query/FilterValidationTest.php::test_shape_columns_accept_the_root_table_and_nothing_else` |
| `orderBy` with a direction other than `asc`/`desc` | Rejected: `InvalidIdentifier` | `tests/Query/ProtectedBuilderTest.php::test_an_unknown_order_direction_is_rejected` |
| `select()` / `orderBy()` / `sum()` column not a plain identifier, or qualified with a table other than the root table | Rejected: `InvalidIdentifier` | `tests/Query/FilterValidationTest.php::test_shape_columns_reject_every_other_form` |
| `limit(n)` / `offset(n)` | Supported | `tests/Query/ProtectedBuilderTest.php::test_order_by_and_limit_and_offset_only_reshape_the_allowed_set` |
| `get()` | Supported | `tests/Kernel/KernelSmokeTest.php::test_sql_and_runtime_agree_and_match_the_expected_set` |
| `first()` / `firstOrFail()` | Supported | `tests/Query/ProtectedBuilderTest.php::test_first_or_fail_behaves_the_same_way` |
| `find($id)` | Supported; returns `null` for a row that exists but is not visible | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` (`find(8)` is `null`) |
| `findOrFail($id)` | Supported | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `count()` | Supported | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `exists()` | Supported (per source) | `tests/Query/ProtectedBuilderTest.php::test_get_count_exists_and_paginate_agree_on_the_same_allowed_set` |
| `sum($column)` | Supported; the column may be qualified with the root table, exactly like a filter column | `tests/Query/ProtectedBuilderTest.php::test_sum_agrees_with_the_visible_rows_on_a_decimal_column`, `tests/Query/FilterValidationTest.php::test_shape_columns_accept_the_root_table_and_nothing_else` |
| `paginate($perPage, $page)` | Supported; `1 ≤ $perPage ≤ 200` | `tests/Kernel/KernelSmokeTest.php::test_a_caller_or_cannot_widen_the_result` |
| `paginate()` with `$perPage` outside `1..200` | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ProtectedBuilderTest.php::test_pagination_bounds_are_enforced` |
| `paginate()` combined with `limit()`/`offset()` | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ProtectedBuilderTest.php::test_pagination_cannot_be_combined_with_limit_or_offset` |
| `paginate()` with a `$page` whose offset would overflow (`$page > intdiv(PHP_INT_MAX, $perPage)`) | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ProtectedBuilderTest.php::test_an_enormous_page_number_is_rejected_cleanly` |
| `with([...])` for a one-level `BelongsTo`/`HasMany` to a model with a policy | Supported | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `with()` with a dotted or `:`-constrained relation name | Rejected: `UnsupportedProtectedOperation` | `tests/Query/FilterValidationTest.php::test_eager_loads_are_one_level_and_uncoloned` |
| `with()` for a relation of any other type, or to a model with no policy | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`unsupported via with()`, `policyless via with()`) |
| `with()` for a relation that adds its own query constraints | Rejected: `UnsupportedProtectedOperation` | — |
| a model without the `HasPrivacyPolicy` trait | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ProtectedBuilderTest.php::test_a_model_without_the_trait_is_refused` |
| a model that overrides a guarded method (`GuardAudit`) | Rejected: `UnsupportedProtectedOperation` | `tests/Query/ProtectedBuilderTest.php::test_a_model_that_overrides_a_guarded_method_is_refused_outright` |
| `null` context passed to `privacyQuery()` / `Privacy::query()` | Rejected: `MissingContext` | `tests/Kernel/KernelSmokeTest.php::test_missing_context_and_missing_facts_are_errors` |
| raw joins, subqueries, unions, `groupBy`/`having`, cursors/lazy collections, `withTrashed`/scope removal, bulk `update`/`delete`/`upsert`/`insert`, polymorphic and many-to-many relation paths, nested eager loads, pivots | Not part of the API — no such method exists on `ProtectedBuilder` | — |

## Predicates: `Exists` and datetime comparison

Not part of `ProtectedBuilder`'s own surface, but part of what a policy
author's rules may express, and directly reachable while a protected query is
being built or a snapshot is being evaluated.

| Operation | Behaviour | Test |
|---|---|---|
| `Exists::in($table)->match($outer, $inner)` (or `matchCols(...)`) correlated on anything other than an integer column on both sides | Rejected: `UncompilablePolicy` (a string key cannot be kept exact on every engine; MariaDB caches a correlated subquery by the outer column's own collation) | `tests/Policy/PredicateConstructionTest.php::test_an_exists_key_must_be_an_integer_on_both_sides`, `tests/Policy/PredicateParityTest.php::test_an_exists_may_only_be_correlated_on_integer_keys` |
| evaluating an `Exists` node with `Runtime\PredicateEvaluator` that was not first batch-prepared with `RelationFactLoader`/`RuntimeEvaluation::prepare()` | Rejected: `MissingFact`; it never lazy-loads per row | `tests/Policy/RuntimeEvaluationTest.php::test_evaluating_an_unprepared_exists_is_an_error` |
| comparing a `datetime` column on SQLite | Compared at millisecond precision (`strftime('%Y-%m-%d %H:%M:%f', ...)`); two instants differing only below a millisecond are not distinguished there, unlike every other engine and the runtime evaluator | `tests/Policy/PredicateParityTest.php::test_sub_millisecond_datetimes_are_the_documented_engine_limit` |

## Returned models

Models hydrated by a protected builder carry the context that produced them.

| Path | Behaviour | Test |
|---|---|---|
| lazy `$m->relation` (`BelongsTo`/`HasMany` to a model with a policy) | Supported; loaded through the related model's own policy under the same context | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `$m->privacyRelation('name')` | Supported: returns a `ProtectedBuilder` constrained by the foreign key | `tests/Query/ReturnedModelTest.php::test_privacy_relation_returns_a_protected_builder_rooted_at_the_related_model` |
| calling a relation method directly, `$m->children()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| a relation of a type other than `BelongsTo`/`HasMany`, or to a model with no policy | Rejected: `UnsupportedProtectedOperation` (per source) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`hasOne`, `belongsToMany`, `morphTo`, `related model without a policy`) |
| `toArray()` / `toJson()` | Supported; serialises what is already loaded (per source: no override exists for these) | `tests/Query/ReturnedModelTest.php::test_an_appended_accessor_that_touches_a_relation_stays_protected` |
| a `serialize()`/`unserialize()` round trip | Supported: the restored instance keeps the protected-instance guards. The context property is declared `protected`, not `private`, specifically so that `Model::__sleep()` (which runs in the parent's scope) retains it | `tests/Query/EscapeRouteTest.php::test_a_serialize_round_trip_does_not_strip_the_guards` |
| `refresh()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `fresh()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `load(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `loadMissing(...)` / `loadAggregate(...)` / `loadMorph(...)` / `loadMorphAggregate(...)` | Rejected: `UnsupportedProtectedOperation` (per source; same guard as `load()`) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`loadMissing` directly; `loadCount`/`loadSum` exercise `loadAggregate()`; `loadMorph`/`loadMorphAggregate` are not separately exercised) |
| `save()` / `saveQuietly()` / `update(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` (`update`, `saveQuietly`); `tests/Action/ActionExecutorTest.php::test_a_model_fetched_under_one_context_is_useless_under_another` (`save`) |
| `delete()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `forceDelete()` / `restore()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` |
| `increment()` / `decrement()` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` (`increment`) |
| `touch()` | Rejected: `UnsupportedProtectedOperation`; guarded directly, not by funnelling into `save()` — without `$timestamps`, `Model::touch()` returns before it would ever reach `save()`, so it needs its own guard | `tests/Query/ReturnedModelTest.php::test_touch_is_rejected_even_when_the_model_has_no_timestamps`, `::test_touch_with_an_attribute_is_rejected` |
| an instance loaded the ordinary way (`Model::query()`, `Model::find()`) | Unchanged: none of the above guards apply | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` (`Note::query()->findOrFail(1)->folder`) |

## `ProtectedCollection`

| Operation | Behaviour | Test |
|---|---|---|
| `load(...)` | Rejected: `UnsupportedProtectedOperation` | `tests/Kernel/KernelSmokeTest.php::test_relations_stay_protected_and_escapes_are_rejected` (`$folder->notes->load('folder')`) |
| `loadMissing(...)` / `loadAggregate(...)` / `loadMorph(...)` / `loadMorphCount(...)` | Rejected: `UnsupportedProtectedOperation` (per source; same guard as `load()`) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`collection loadMissing` directly; `collection loadCount` exercises `loadAggregate()`; `loadMorph`/`loadMorphCount` are not separately exercised) |
| `fresh(...)` | Rejected: `UnsupportedProtectedOperation` (per source) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`collection fresh`) |
| `toQuery()` | Rejected: `UnsupportedProtectedOperation` (per source) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`collection toQuery`) |
| `withRelationshipAutoloading()` | Rejected: `UnsupportedProtectedOperation` (per source) | `tests/Query/ReturnedModelTest.php::test_every_rejected_operation_throws_and_changes_nothing` (`collection autoloading`); also `tests/Query/ReturnedModelTest.php::test_the_relationship_autoloading_switch_creates_no_bypass` |
| every other `Illuminate\Database\Eloquent\Collection` method (`filter`, `map`, `modelKeys`, ...) | Supported; unmodified from Eloquent | `tests/Query/EscapeRouteTest.php::test_collection_transformations_keep_the_models_protected` |

## Actions

| Operation | Behaviour | Test |
|---|---|---|
| `ActionExecutor::execute()` sequence (transaction → anchors → pre-state → facts → authorise → validate → persist → commit → after-commit) | Supported, in this fixed order | `tests/Action/ActionExecutorTest.php::test_facts_are_resolved_inside_the_transaction_after_the_locks` |
| anchor locks taken in `(table, key)` order, de-duplicated | Supported | `tests/Action/AnchorLockingTest.php::test_anchors_are_locked_in_sorted_order_whatever_order_they_were_declared_in`, `::test_the_order_is_total_and_de_duplicated` |
| anchor lock is a real `FOR UPDATE` on a real engine | Supported | `tests/Action/AnchorLockingTest.php::test_the_lock_is_a_real_for_update_on_a_real_engine` |
| a named anchor row that does not exist | Rejected: `MissingLockedRow` | `tests/Action/AnchorLockingTest.php::test_a_missing_anchor_row_fails_the_action` |
| `Privacy::withAnchors()` — a participating grant writer takes the same locks in the same order | Supported | `tests/Action/AnchorLockingTest.php::test_a_participating_grant_writer_takes_the_same_locks_in_the_same_order` |
| `Privacy::withAnchors()` whose anchor row is missing | Rejected: `MissingLockedRow`; the writer's closure never runs | `tests/Action/AnchorLockingTest.php::test_a_grant_writer_whose_anchor_is_missing_does_not_run` |
| a caller-held / caller-mutated instance passed to an action | Ignored beyond its key: the executor always re-loads and locks its own copy | `tests/Action/ActionExecutorTest.php::test_an_instance_the_caller_mutated_contributes_nothing_but_its_key` |
| a model fetched under one context, then acted on under another | The fetch context is irrelevant; authorisation is re-evaluated | `tests/Action/ActionExecutorTest.php::test_a_model_fetched_under_one_context_is_useless_under_another` |
| a proposed change to the owner/foreign-key column | Never authorises itself; rules see persisted pre-state and proposed changes separately | `tests/Action/ActionExecutorTest.php::test_a_dirty_owner_field_cannot_authorise_itself`, `tests/Action/ActionAuthorisationTest.php::test_a_predicate_rule_sees_the_persisted_pre_state_not_the_proposed_changes` |
| reparenting (a change to the parent foreign key) | The proposed parent is loaded and locked, and must itself be authorised; loaded only when the changes touch the foreign key | `tests/Action/ActionExecutorTest.php::test_reparenting_needs_the_proposed_parent_authorised`, `::test_the_proposed_parent_is_loaded_only_when_the_changes_touch_the_foreign_key` |
| creation (`targetKey() === null`) | Authorised through the creation parent only; a reused `PredicateRule` cannot authorise it (no persisted pre-state) | `tests/Action/ActionExecutorTest.php::test_a_creation_is_authorised_through_the_creation_parent`, `tests/Action/ActionAuthorisationTest.php::test_a_predicate_rule_cannot_authorise_a_creation` |
| a `PredicateRule` reused as an action rule that reads a relationship (`Exists`/`ViaParent`) | Rejected: `UnsupportedProtectedOperation` | `tests/Action/ActionAuthorisationTest.php::test_a_predicate_rule_that_reads_a_relationship_is_rejected` |
| action facts resolved via `ActionFactProvider` | Read under lock (`LockingReads`), after the anchor lock and the pre-state load | `tests/Action/ActionExecutorTest.php::test_facts_are_resolved_inside_the_transaction_after_the_locks`, `tests/Action/AnchorLockingTest.php::test_the_lock_is_a_real_for_update_on_a_real_engine` |
| an anonymous viewer against a rule set without `allowAnonymous()` | Denied before any lock is taken and before any rule runs | `tests/Action/ActionAuthorisationTest.php::test_an_anonymous_viewer_is_denied_before_any_rule_runs` |
| an anonymous viewer against a rule set with `allowAnonymous()` | Rules run normally | `tests/Action/ActionAuthorisationTest.php::test_a_rule_set_that_allows_anonymous_viewers_runs_its_rules` |
| a stage outcome the stage does not permit | Rejected: `InvalidOutcome`, wrapped in `StageEvaluationFailed`; rolls back | `tests/Action/ActionAuthorisationTest.php::test_an_outcome_a_stage_does_not_permit_fails_the_stage_and_rolls_back` |
| a missing fact read inside a rule | `MissingFact`, wrapped in `StageEvaluationFailed`; rolls back, not audited as a denial | `tests/Action/ActionAuthorisationTest.php::test_a_missing_fact_fails_the_stage_and_rolls_back`, `::test_an_error_is_not_reported_to_the_denial_auditor` |
| a `Deny` decision (or the default terminal) | Rejected: `ActionDenied`; rolls back, changes nothing, no after-commit callback runs | `tests/Action/ActionExecutorTest.php::test_a_denial_changes_nothing_audits_once_and_runs_no_after_commit_callback` |
| a denial | Reported to the optional `DenialAuditor`, after rollback, identifiers only | `tests/Action/ActionExecutorTest.php::test_a_denial_changes_nothing_audits_once_and_runs_no_after_commit_callback` |
| `validate()` throwing | Rolls the action back; no after-commit callback runs | `tests/Action/ActionExecutorTest.php::test_a_validation_failure_rolls_the_action_back` |
| a model with a read policy but no policy for the attempted action | Rejected: `PolicyNotRegistered`; a read policy never authorises a write | `tests/Action/ActionExecutorTest.php::test_a_read_policy_never_authorises_a_write` |
| `null` context passed to `execute()` | Rejected: `MissingContext` | `tests/Action/ActionExecutorTest.php::test_a_null_context_is_an_error` |
| an action nested inside an open transaction | Runs in a savepoint; a denial rolls back to the savepoint, leaving the outer transaction usable | `tests/Action/NestedTransactionTest.php::test_a_denial_rolls_back_to_the_savepoint_and_leaves_the_outer_transaction_usable` |
| an action nested inside another action | Its own savepoint | `tests/Action/NestedTransactionTest.php::test_an_action_nested_in_an_action_uses_its_own_savepoint` |
| `afterCommit()` callback, nested transaction | Runs once, at the outermost commit; never if the outer transaction rolls back | `tests/Action/NestedTransactionTest.php::test_after_commit_work_waits_for_the_outermost_commit`, `::test_after_commit_work_never_runs_when_the_outer_transaction_rolls_back` |
| `ActionResult` / `Receipt` | Never carries the persisted model, only identifiers | `tests/Action/ActionExecutorTest.php::test_the_result_never_carries_the_persisted_model` |
| `ActionResult::readable()` | Re-queries through the protected read path under the same context; `null` when the caller may write but not read, or when the model has no read policy | `tests/Action/ActionResultTest.php::test_readable_returns_the_row_through_the_protected_read_path`, `::test_readable_is_null_for_a_caller_that_may_write_but_not_read`, `::test_readable_is_null_when_the_model_has_no_read_policy` |
| `ActionResult::readable()` called more than once | Re-queries every time; does not hold the row | `tests/Action/ActionResultTest.php::test_readable_re_queries_every_time_rather_than_holding_the_row` |
| revocation protocol: a revoker that takes the anchor before the action | The action waits for the anchor, then reads the grant already revoked; denied | `tests/Concurrency/RevocationRaceTest.php::test_a_revoker_that_takes_the_anchor_first_denies_the_action` |
| revocation protocol: an action that takes the anchor before the revoker | The revoker waits for the anchor until the action commits; the action's write and the later revocation both land | `tests/Concurrency/RevocationRaceTest.php::test_an_action_that_takes_the_anchor_first_makes_the_revoker_wait` |
| revocation protocol: a grant writer that does not take the anchor | Not covered — it can interleave mid-action and change the answer the action reads | `tests/Concurrency/RevocationRaceTest.php::test_a_grant_writer_that_takes_no_anchor_is_not_covered` |
| a plain (non-locking) read of a fact inside a transaction, vs. `LockingReads` | A plain read can be stale (`REPEATABLE READ` snapshot); `LockingReads` is always current, including inside a savepoint | `tests/Concurrency/SnapshotStalenessTest.php::test_a_plain_read_can_be_stale_where_a_locking_read_is_current` |
