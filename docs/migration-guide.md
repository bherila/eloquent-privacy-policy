# Migration guide: adopting this in an existing application

This describes a staged way to introduce the package into an application that
already has its own visibility and authorisation logic. It assumes the
reader has read `README.md` first, and treats `docs/contract.md` as the
authority on what any given API actually does.

Nothing here is validated by this package's own test suite — it is operational
guidance, not a tested code path. Treat it as a starting checklist, not a
recipe with guarantees.

## Before writing any adoption code: characterise current behaviour

Write tests against the *existing* access-control behaviour before touching
it, for the model and the call sites you are about to migrate. You are about
to replace logic that has been shaping production behaviour, possibly for
years; a regression is much cheaper to catch against a test that pins down
today's behaviour than against a policy you just wrote and therefore already
believe is correct.

At minimum, pin down:

- which rows each representative viewer/role currently sees, for the model
  you are migrating;
- what the current code returns for a viewer who should see nothing (empty
  collection? `null`? a 403? a 404?) — `find()`/`findOrFail()` on a protected
  builder return "not found" for a row that exists but is not visible
  (contract §5), so if your current behaviour distinguishes "doesn't exist"
  from "exists but hidden" for this model, decide up front whether you are
  intentionally collapsing that distinction;
- what the current write path allows for the same viewers.

## Inventory call sites

Find every place the model is queried or written: controllers, jobs, console
commands, other models' relations, event listeners. `Privacy::hasPolicy()`
does not know about any of them — adopting the trait and registering a policy
changes nothing about a call site that keeps calling `Model::query()`. The
inventory is what tells you whether "adopt one model" is actually safe, or
whether the model is queried from twelve places you have not touched yet.

## Adopt one model and one path at a time

Add `HasPrivacyPolicy` and a policy to one model. Migrate one call site to
`privacyQuery()` / the action executor. Ship it. Move to the next call site.
Do not add the trait to several models and rewire every call site in the same
change — a regression in a multi-model, multi-path change is much harder to
bisect than one in a single-model, single-path change.

**Keep the existing secured path as the rollback.** If the new protected path
misbehaves, revert the call site to the application's previous, already-secured
query — never to an unfiltered query "just for now." An unfiltered fallback is
not a rollback, it is a new and worse incident. The same applies to a
partially-migrated call site under load or under an unexpected error: fail
toward the narrower, previously-correct behaviour, never toward "show
everything."

## Mapping existing concepts

- **Existing `visibleTo`-style scopes.** A local scope such as
  `Model::scopeVisibleTo($query, $user)` can delegate to `privacyQuery()`
  once the model has a policy — call the protected builder and copy the rows
  it decided into wherever the scope needs them — but the two are not
  interchangeable in general: a local scope composes into an arbitrary
  Eloquent query the caller controls, while a protected builder is a
  self-contained query that never exposes the underlying builder (contract
  §5). Do not try to make `privacyQuery()` return something that plugs into
  an existing `Builder` chain; migrate the call site to use the protected
  builder's own methods instead.
- **Global scope interplay.** A registered global scope (soft deletes, a
  tenant scope, etc.) still applies to the underlying Eloquent query
  `ProtectedBuilder` composes (contract §5: "`<model global scopes>` AND
  `(privacy)` AND `(caller)`"). If the application currently relies on a
  global tenant scope for isolation, decide explicitly whether the privacy
  policy's own mandatory stage should also enforce that boundary (redundant
  but explicit) or whether you are relying on the global scope alone — the
  latter means the boundary is invisible to anyone reading the policy.
  A model with such a scope cannot be the parent of a `ViaParent`: the
  expansion reproduces the parent's privacy policy and its soft-delete column
  only, so it refuses (`UncompilablePolicy`) rather than leave a child visible
  whose parent the scope hides. State the boundary in the parent's mandatory
  stage, where `ViaParent` does carry it.
- **Headless / CLI contexts.** A console command or a cron job has no
  authenticated user. Contract §3 requires an explicit context — there is no
  implicit "console is privileged" path, and there must not be one built on
  top of it either: running from a console is not, by itself, authority for
  anything. Build the context the same way an HTTP request would (an explicit
  viewer, or `Viewer::anonymous()` with a rule set that opts in via
  `allowAnonymous()`), and if the job genuinely needs elevated access, express
  that as a narrowly-scoped capability or a privileged rule with its own
  justification, not as "no context needed because it's a job."
- **Queue jobs.** A model captured before a job was queued and restored on the
  worker is an ordinary model with no privacy context (contract §5.3). Do not
  serialise a decision, an `ActionResult`, or a protected instance into a job
  payload and trust it later. Re-authorise from identifiers when the job
  runs, exactly as if the request were arriving fresh.

## Making existing grant writers participate

Every place that mutates a row a policy's grant stage depends on — an
invitation acceptance, a role change, a share, a revocation — is a candidate
grant writer. Contract §6.1's revocation protocol only covers a writer that
wraps its mutation in `Privacy::withAnchors()`, taking the same anchor(s) an
action locks, in the same order.

1. List every writer of the grant table(s) a migrated action's rules read.
2. For each one, either:
   - wrap it in `Privacy::withAnchors([...], fn () => ...)` with the same
     anchor the corresponding action uses, or
   - explicitly document it as **not participating** and accept the
     consequence: contract §6.1 and
     `tests/Concurrency/RevocationRaceTest::test_a_grant_writer_that_takes_no_anchor_is_not_covered`
     describe exactly what "not covered" means — such a writer can commit in
     the middle of an in-flight action and change the answer the action
     reads, in either direction.
3. Keep the list current. A grant table that starts with one participating
   writer can silently gain a second, non-participating one (a new admin
   tool, a bulk import script, a one-off console command) — nothing in the
   package enforces "every writer of this table participates" (see
   `docs/threat-model.md`, residual risk 3).

## What to measure

Before relying on a migrated path in production:

- **Query counts at a small and a larger row count** (the benchmark's own
  comparison points are 10 and 100 rows — see `benchmarks/README.md`).
  `with([...])` for a `ViaParent` relation compiles to a correlated `EXISTS`
  in the same statement, not an N+1 (contract §4.4;
  `benchmarks/README.md`, "Results"), but confirm this for your own policy
  shape rather than assuming the benchmark's shape generalises — a policy
  with more `Exists`/`ViaParent` nesting was explicitly not measured there.
- **Query plans**, not just counts. `benchmarks/README.md` shows that the
  package and a hand-written equivalent get identical access paths on both
  SQLite and MariaDB for its one measured policy shape; verify the same for
  yours with `EXPLAIN` before assuming it holds.
- **Fixed per-call cost.** `PolicyResolver` runs on every `privacyQuery()`
  call (contract §4.4); this cost is independent of row count and is most
  visible on small, fast queries (`benchmarks/README.md`, "Where the
  package's own overhead most likely comes from").

## What not to do

- **No positive permission cache.** Facts and decisions are operation-local by
  design (contract §3: "The package keeps no static or cross-operation cache
  of contexts, facts, decisions, or protected models"). Do not build one on
  top: caching "viewer X may see row Y" across operations reintroduces
  exactly the staleness the revocation protocol exists to prevent, and the
  package's own guarantees say nothing about a cache you added yourself.
- **No model-wide strict switch.** There is no "protect every query against
  this model from now on" toggle (contract §5.3 lists this among the staged,
  not-yet-implemented items). Adoption is call-site by call-site; do not
  simulate a strict mode by, for example, throwing from the model's ordinary
  `booted()` hook — that changes behaviour for every unmigrated call site at
  once, which is the opposite of staged adoption.
- **No claim of whole-application coverage after a pilot.** Migrating one
  model, or one path on one model, proves that path is protected. It proves
  nothing about the other paths in the inventory you have not migrated yet.
  Track the inventory to completion (or to an explicit, documented decision
  not to migrate a given path) before describing the model itself as
  protected.
