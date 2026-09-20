# eloquent-privacy-policy

A small SQL-first enforcement library for existing Eloquent models. A policy
is declared once as data and interpreted twice: compiled into the `WHERE`
clause of a collection query, and evaluated in PHP against a row snapshot. It
is not an ORM, not a permissions service, and not a sandbox — application PHP
that holds database credentials can always run an unprotected query. The
guarantee covers the protected API described here and nothing else.

Ordinary queries on a model (`Model::query()`, `Model::find()`, relationships
on an ordinarily loaded instance) are completely unchanged by adopting this
package. Adoption is per model and per call site, not global.

> **Status: first reviewable slice.** The API may still change. This is **not
> yet recommended for adoption** in an application. See
> ["Status and staged work"](#status-and-staged-work) below before relying on
> anything here, and `docs/contract.md` for the single source of truth on
> exactly what is and is not implemented.

## Requirements

- PHP `^8.4`
- Laravel 13 components: `illuminate/contracts`, `illuminate/database`,
  `illuminate/support` (`^13.0`)

**Tested engines.** The Package suite (PHP 8.4 and 8.5) and the Concurrency
suite (PHP 8.4) have been run against exactly these versions:

| Engine  | Version            | Where          | Suites                         |
|---------|--------------------|----------------|--------------------------------|
| SQLite  | 3.45.1, 3.53.4     | CI, local      | Package (foreign keys enabled) |
| MySQL   | 8.4.11             | CI             | Package, Concurrency           |
| MariaDB | 11.4.13, 10.11.19  | CI, local      | Package, Concurrency           |

Nothing is inferred across engines: the revocation protocol is claimed for
MySQL and MariaDB only, and SQLite is never used as evidence of locking
behaviour. The benchmark was measured on SQLite and MariaDB 10.11 only.

## Install

This package is **not on Packagist** and has **no tagged release**. Point
Composer at the repository directly and pin an exact commit — never a branch
— so that installing it gives the same code every time:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/bherila/eloquent-privacy-policy"
        }
    ],
    "require": {
        "bherila/eloquent-privacy-policy": "dev-main#<commit-sha>"
    }
}
```

Replace `<commit-sha>` with the commit you have actually reviewed. Then:

```bash
composer update bherila/eloquent-privacy-policy
```

## Quick start

### 1. Add the trait and define a policy with all five stages

```php
use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    use HasPrivacyPolicy;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()
                // 1. mandatory: a boundary every row must satisfy.
                ->mandatory(Rule::of('tenant', fn (PrivacyContext $c) => Col::int('tenant_id')->eq($c->scope->int('tenant_id'))))
                // 2. privileged: an Allow here skips everything below.
                ->privileged(Rule::of('staff', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('is_staff'))))
                // 3. deny: a Deny here beats every grant below.
                ->deny(Rule::of('locked', fn () => Col::bool('locked')->eq(true)))
                // 4. grant: any one of these being true is enough.
                ->grant(
                    Rule::of('author', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('shared', fn (PrivacyContext $c) => Exists::in('doc_record_shares')
                        ->match('id', 'record_id')
                        ->where(Predicate::all(
                            Col::int('user_id')->eq($c->viewer->intId()),
                            Predicate::any(Col::datetime('expires_at')->isNull(), Col::datetime('expires_at')->gt($c->now)),
                        ))),
                    Rule::of('project', fn () => ViaParent::of('project_id', Project::class)),
                ))
            // 5. terminal: the default when nothing above decided (Deny unless set otherwise).
            ->action('record.update', RuleSet::define()->grant(
                Rule::action('write-grant', fn (PrivacyContext $c) => $c->facts->bool('may_write')
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }
}
```

`Project` is a sibling model with its own policy (for example, a `grant` rule
that a `project.owner_id` matches the viewer) — `ViaParent` only expands a
foreign key into "is the parent row visible under the parent's own read
policy", it does not require the parent's policy to look any particular way.

### 2. Build a context in a trusted adapter

Contexts are built by application code after authentication, feature gates and
section access have already succeeded — never from client-supplied role
claims. A context is a plain, scalar snapshot (`docs/contract.md` §3):

```php
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;

$context = new PrivacyContext(
    Viewer::identified($request->user()->id),
    new Operation('record.list'),
    new ResourceScope(['tenant_id' => $request->user()->tenant_id]),
    facts: new Facts(['is_staff' => $request->user()->is_staff]),
);
```

### 3. Run a protected query

```php
$records = Record::privacyQuery($context)
    ->orderBy('id')
    ->get();
```

Every protected query executes as exactly one shape, regardless of what the
caller adds:

```
<model global scopes, e.g. soft deletes>  AND  ( privacy )  AND  ( caller )
```

The privacy predicate and the caller's own filters are each confined to their
own nested group, so a caller `orWhere` can narrow the result but can never
widen it past what the policy allows
(`tests/Docs/ReadmeExamplesTest::test_quick_start_a_caller_or_cannot_widen_the_result`).

### 4. Relations on returned models

A model returned by a protected builder carries its context. What that means
for its relations:

| You do | What happens |
|---|---|
| `$record->project` (lazy `BelongsTo`) | Loaded through `Project`'s own policy, under the same context |
| `Record::privacyQuery($context)->with(['project'])` | Same, eager |
| `$record->project()` | Throws `UnsupportedProtectedOperation` — it would hand out a raw, unguarded builder |
| `$record->refresh()`, `->fresh()`, `->load(...)` | Throws `UnsupportedProtectedOperation` — re-query through a protected builder instead |
| `$record->save()`, `->delete()`, `->update(...)`, `->increment(...)` | Throws `UnsupportedProtectedOperation` — a read never authorises a write; use an action |
| a relation to a model with no policy, or of any type other than `BelongsTo`/`HasMany` | Throws `UnsupportedProtectedOperation` |

Full matrix, with the test that proves each row: `docs/supported-operations.md`.

### 5. An action end to end

A successful read authorises nothing else. Writes go through
`ActionExecutor`, which fixes the sequence: open a transaction, lock the
action's anchors, load the authoritative pre-state under lock, resolve facts
under lock, authorise, validate, persist, commit, then run after-commit work.

```php
use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/** "May the viewer write in this project?", read under lock. */
final class RecordGrants implements ActionFactProvider
{
    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        $grant = $reads->table('doc_record_grants')
            ->where('project_id', '=', $input->parent?->get('id'))
            ->where('user_id', '=', $context->viewer->id())
            ->where('ability', '=', 'write')
            ->first(['id']);

        return new Facts(['may_write' => $grant !== null]);
    }
}

final class RecordUpdateAction extends BaseAction
{
    public function __construct(
        private readonly int $recordId,
        private readonly int $projectId,
        private readonly string $title,
    ) {
    }

    public function name(): string { return 'record.update'; }
    public function model(): string { return Record::class; }
    public function targetKey(): int { return $this->recordId; }
    public function changes(): array { return ['title' => $this->title]; }
    public function anchors(): array { return [Anchor::of('doc_projects', $this->projectId)]; }
    public function parent(): ParentLink { return ParentLink::of('project_id', Project::class); }
    public function facts(): ActionFactProvider { return new RecordGrants(); }

    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->title = $this->title;
        $target->revision = $target->revision + 1;
        $target->save();

        return $target;
    }

    public function version(Model $persisted): int { return $persisted->revision; }
}
```

Running it:

```php
use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;

$result = (new ActionExecutor())->execute(
    new RecordUpdateAction(recordId: 4, projectId: 1, title: 'Delta (renamed)'),
    $context,
);

$result->receipt->action;     // 'record.update'
$result->receipt->targetKey;  // 4
$result->receipt->version;    // the new revision

$row = $result->readable();   // re-queries through the protected read path; null if the
                               // caller may write but not read what it just wrote
```

`ActionResult` never carries the persisted model itself — only a `Receipt`
(identifiers and an optional version). `readable()` is the only way back to
the row, and it goes through the same protected read path as any other query.

### 6. The grant-writer side of a revocation

An action names the rows its authorisation depends on (its **anchors**) and
locks them before reading any grant. A grant writer that wants the same
protection wraps its mutation the same way, taking the same locks in the same
order:

```php
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Privacy;

Privacy::withAnchors(
    [Anchor::of('doc_projects', $projectId)],
    fn () => DB::table('doc_record_grants')->where('project_id', $projectId)->delete(),
);
```

This is opt-in per writer. `docs/threat-model.md` and
`docs/contract.md` §6.1 are explicit about what a writer that skips it gets:
nothing — such a writer can commit in the middle of an in-flight action and
change the answer the action reads, in either direction.

### Errors

Every failure raised by the package extends `PrivacyException`. Condensed
from `docs/contract.md` §2:

| Exception | Raised when |
|---|---|
| `MissingContext` | No context was supplied (an explicit anonymous context is different from none at all) |
| `MissingFact` | A rule read a fact the context does not carry |
| `MissingAttribute` | Runtime evaluation needed a column a snapshot does not contain (partial select) |
| `InvalidOutcome` | A rule returned an outcome its stage does not permit |
| `AttributeTypeMismatch` | A raw value cannot be normalised to a column's declared type |
| `PolicyCycle` | `ViaParent` expansion revisited a model already on the expansion stack |
| `PolicyNotRegistered` | A protected path reached a model with no policy (or an action with no policy for that action name) |
| `UncompilablePolicy` | A read policy contains something the SQL compiler cannot express |
| `UnsupportedProtectedOperation` | A protected builder/model/collection was asked to do something outside the supported matrix |
| `InvalidIdentifier` | A table, column, operator or direction is not a plain trusted identifier |
| `ActionDenied` | An action's rule set reduced to `Deny` |
| `StageEvaluationFailed` | One or more rules in an entered stage threw; wraps every failure, ordered by rule id |

## Domain examples

Three synthetic examples, each exercised end to end by
`tests/Docs/ReadmeExamplesTest.php` against SQLite. None of these model any
real application; hosts are never named.

### (a) Patient-owned clinical records

A patient owns their own records. A caregiver can be granted access to a
patient with an ability ("view" or "write") that expires. Deleting a record is
reserved for the patient themselves — no grant, however broad, satisfies it.

```php
class Patient extends Model
{
    use HasPrivacyPolicy;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('self', fn (PrivacyContext $c) => Col::int('user_id')->eq($c->viewer->intId())),
                Rule::of('grant', fn (PrivacyContext $c) => Exists::in('doc_patient_grants')
                    ->match('id', 'patient_id')
                    ->where(Predicate::all(
                        Col::int('user_id')->eq($c->viewer->intId()),
                        Predicate::any(Col::datetime('expires_at')->isNull(), Col::datetime('expires_at')->gt($c->now)),
                    ))),
            ),
        );
    }
}

class ClinicalRecord extends Model
{
    use HasPrivacyPolicy;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            // Child visibility via ViaParent: no separate owner/grant check here.
            ->read(RuleSet::define()->grant(
                Rule::of('via-patient', fn () => ViaParent::of('patient_id', Patient::class)),
            ))
            ->action('record.update', RuleSet::define()->grant(
                Rule::action('write-grant', fn (PrivacyContext $c) => $c->facts->bool('may_write')
                    ? Decision::Allow
                    : Decision::Skip),
            ))
            // A grant label cannot satisfy this: only the patient may delete.
            ->action('record.delete', RuleSet::define()->grant(
                Rule::action('is-patient', fn (PrivacyContext $c, ActionInput $i) => $i->parent !== null
                    && (string) $i->parent->get('user_id') === (string) $c->viewer->id()
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }
}
```

### (b) Workspace Q&A

Everything is scoped to one workspace, read from the resource boundary rather
than from another row. Within that boundary: your own question, any FAQ,
anything you are tagged a collaborator on, or anything belonging to an
organisation you are a member of — unless it is archived, which hides it from
everyone. Editing is narrower than reading: seeing a thread is not being able
to change it.

```php
class Question extends Model
{
    use HasPrivacyPolicy;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()
                // The boundary reads the context's resource scope, not a column
                // comparison against another row.
                ->mandatory(Rule::of('workspace', fn (PrivacyContext $c) => Col::int('workspace_id')->eq($c->scope->int('workspace_id'))))
                ->deny(Rule::of('archived', fn () => Col::bool('archived')->eq(true)))
                ->grant(
                    Rule::of('own', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('faq', fn () => Col::bool('is_faq')->eq(true)),
                    Rule::of('collaborator', fn (PrivacyContext $c) => Exists::in('doc_question_collaborators')
                        ->match('id', 'question_id')
                        ->where(Col::int('user_id')->eq($c->viewer->intId()))),
                    Rule::of('organisation', fn (PrivacyContext $c) => Col::int('org_id')->in($c->facts->ints('member_org_ids'))),
                ))
            // Being able to see a thread is not being able to edit it: none of
            // FAQ, collaborator or organisation membership appear here.
            ->action('question.edit', RuleSet::define()->grant(
                Rule::action('author', fn (PrivacyContext $c, ActionInput $i) => $i->preState !== null
                    && (string) $i->preState->get('author_id') === (string) $c->viewer->id()
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }
}
```

### (c) Parent-owned finance records

An account has a custom primary key (`account_no`, not `id`) and an owner
column (`holder_id`) distinct from it. An entry has no owner column at all —
its visibility comes only from its account, via `ViaParent`. Reparenting an
entry to a different account needs the viewer to hold both the account it is
leaving and the one it would join.

```php
class LedgerAccount extends Model
{
    use HasPrivacyPolicy;

    protected $primaryKey = 'account_no';

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('holder', fn (PrivacyContext $c) => Col::int('holder_id')->eq($c->viewer->intId())),
            ),
        );
    }
}

class LedgerEntry extends Model
{
    use HasPrivacyPolicy;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()->grant(
                Rule::of('via-account', fn () => ViaParent::of('account_no', LedgerAccount::class)),
            ))
            ->action('entry.move', RuleSet::define()->grant(
                // Owning the account an entry is leaving says nothing about the
                // one it would join: both must hold, when the account changes.
                Rule::action('owns-both-accounts', fn (PrivacyContext $c, ActionInput $i) => self::holds($i->parent, $c)
                    && (! $i->changes('account_no') || self::holds($i->proposedParent, $c))
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }

    private static function holds(?RowSnapshot $account, PrivacyContext $c): bool
    {
        return $account !== null && (string) $account->get('holder_id') === (string) $c->viewer->id();
    }
}
```

## Further reading

- `docs/contract.md` — the source of truth for the API and its semantics.
- `docs/supported-operations.md` — the full supported / rejected / not-offered
  matrix, with a proving test for every row that has one.
- `docs/threat-model.md` — assets, trust boundaries, what the protected API
  defends against, what it explicitly does not, and residual risks.
- `docs/migration-guide.md` — staged adoption in an existing application.
- `benchmarks/README.md` — `privacyQuery()` measured against a hand-written
  equivalent, with query plans.

## Status and staged work

This is a first reviewable slice, not a finished library. In particular it is
**not** described anywhere in this repository as integration-ready,
production-ready, secure, complete, or compliant — every capability above is
backed by a test, and every limitation below is real.

`docs/contract.md` marks the following **[staged]** — specified, not yet
implemented; calling it either throws `UnsupportedProtectedOperation` or the
API for it simply does not exist yet:

- cursor pagination
- relations that carry their own query constraints
- a way for the application to distinguish "not visible" from "visible but
  this action is not permitted" without the package disclosing either answer
  to the client
- static-analysis checks that flag an ordinary (unprotected) query on an
  adopted model inside an adopted path
- model-wide strict adoption (an application-wide "always protect this model"
  switch)

Also staged, per contract §7: the concurrency-related extension seams
(`GroupExecutor`, `FactProvider`) exist only as interfaces with one
synchronous implementation each; no concurrent stage evaluation, scheduler,
fibre, process pool, or shared cache is part of this package.

Beyond the contract's own list:

- **No Packagist release.** See "Install" above — this is a VCS-repository,
  pinned-commit dependency only.
- **MySQL runs in CI only.** MySQL 8.4 is exercised by the CI matrix (Package and
  Concurrency suites); it was not available locally, and the benchmark was not
  measured on it.
- **Static-analysis checks are not implemented.** This is the same item as
  above from the contract's list, called out again here because it is easy to
  read past: nothing in this package will warn you if you keep querying an
  adopted model the unprotected way from a path you meant to migrate.
