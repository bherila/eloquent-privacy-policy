<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Benchmark-only mirror of tests/Kernel/Folder.php. Same policy, same shape,
 * own `bench_`-prefixed table so it cannot collide with the Kernel fixtures or
 * with other agents' tables in the shared `testing` database.
 */
final class BenchFolder extends Model
{
    use HasPrivacyPolicy;
    use SoftDeletes;

    protected $table = 'bench_folders';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c) => Col::int('owner_id')->eq($c->viewer->intId())),
            ),
        );
    }

    /** @return HasMany<BenchNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(BenchNote::class, 'folder_id');
    }
}
