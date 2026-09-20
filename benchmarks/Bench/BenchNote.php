<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Benchmark-only mirror of tests/Kernel/Note.php — identical policy shape
 * (tenant boundary, staff bypass, locked deny, author/share/folder grants),
 * pointed at its own `bench_`-prefixed tables so it never touches the Kernel
 * fixtures or another agent's tables in the shared `testing` database.
 *
 * Visible when: the note's tenant matches (mandatory) AND ( staff (privileged)
 * OR ( not locked (deny) AND ( author, shared, or the folder is visible ) ) ).
 */
final class BenchNote extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'bench_notes';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()
                ->mandatory(Rule::of('tenant', fn (PrivacyContext $c) => Col::int('tenant_id')->eq($c->scope->int('tenant_id'))))
                ->privileged(Rule::of('staff', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('is_staff'))))
                ->deny(Rule::of('locked', fn () => Col::bool('locked')->eq(true)))
                ->grant(
                    Rule::of('author', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('shared', fn (PrivacyContext $c) => Exists::in('bench_note_shares')
                        ->match('id', 'note_id')
                        ->where(Predicate::all(
                            Col::int('user_id')->eq($c->viewer->intId()),
                            Predicate::any(Col::datetime('expires_at')->isNull(), Col::datetime('expires_at')->gt($c->now)),
                        ))),
                    Rule::of('folder', fn () => ViaParent::of('folder_id', BenchFolder::class)),
                ),
        );
    }

    /** @return BelongsTo<BenchFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(BenchFolder::class, 'folder_id');
    }
}
