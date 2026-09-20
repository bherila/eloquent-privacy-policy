<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Query;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingContext;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Predicate\Identifier;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Sql\PredicateCompiler;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A query over one model that always executes as
 *
 *     <model global scopes> AND ( privacy ) AND ( caller filters )
 *
 * It composes a native Eloquent builder and never exposes it: there is no
 * __call, no macro forwarding and no accessor for the underlying query.
 *
 * @template TModel of Model
 */
final class ProtectedBuilder
{
    public const int MAX_PER_PAGE = 200;

    private FilterGroup $filters;

    /** @var list<array{string, int|string|null}> trusted key constraints (relation keys, find) */
    private array $keys = [];

    /** @var list<string>|null qualified column names */
    private ?array $columns = null;

    /** @var list<array{string, 'asc'|'desc'}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<string, RelationShape> */
    private array $with = [];

    /**
     * @param TModel $prototype
     */
    private function __construct(
        private readonly Model $prototype,
        private readonly PrivacyContext $context,
        private readonly Predicate $privacy,
    ) {
        $this->filters = new FilterGroup($prototype->getTable());
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     * @return self<T>
     */
    public static function for(string $model, ?PrivacyContext $context): self
    {
        if ($context === null) {
            throw new MissingContext(sprintf(
                'A protected query on %s needs a context. Use an explicit anonymous context for anonymous access.',
                $model,
            ));
        }

        if (! in_array(HasPrivacyPolicy::class, class_uses_recursive($model), true)) {
            throw new UnsupportedProtectedOperation(sprintf(
                '%s must use the HasPrivacyPolicy trait: returned models could not be guarded otherwise.',
                $model,
            ));
        }

        GuardAudit::assertIntact($model);

        return new self(new $model(), $context, (new PolicyResolver())->resolveRead($model, $context)->toPredicate());
    }

    // ----- caller filters ---------------------------------------------------

    /** @return $this */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        func_num_args() === 2 ? $this->filters->where($column, $operator) : $this->filters->where($column, $operator, $value);

        return $this;
    }

    /** @return $this */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        func_num_args() === 2 ? $this->filters->orWhere($column, $operator) : $this->filters->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * @param iterable<mixed> $values
     * @return $this
     */
    public function whereIn(string $column, iterable $values): static
    {
        $this->filters->whereIn($column, $values);

        return $this;
    }

    /**
     * @param iterable<mixed> $values
     * @return $this
     */
    public function whereNotIn(string $column, iterable $values): static
    {
        $this->filters->whereNotIn($column, $values);

        return $this;
    }

    /** @return $this */
    public function whereNull(string $column): static
    {
        $this->filters->whereNull($column);

        return $this;
    }

    /** @return $this */
    public function whereNotNull(string $column): static
    {
        $this->filters->whereNotNull($column);

        return $this;
    }

    /** @return $this */
    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->filters->whereBetween($column, $from, $to);

        return $this;
    }

    // ----- shape --------------------------------------------------------------

    /**
     * @param list<string> $columns the primary key is always added
     * @return $this
     */
    public function select(array $columns): static
    {
        $qualified = [$this->qualify($this->prototype->getKeyName())];

        foreach ($columns as $column) {
            $qualified[] = $this->qualify($column);
        }

        $this->columns = array_values(array_unique($qualified));

        return $this;
    }

    /** @return $this */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidIdentifier('Order direction must be "asc" or "desc".');
        }

        $this->orders[] = [$this->qualify($column), $direction];

        return $this;
    }

    /** @return $this */
    public function limit(int $limit): static
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Eager-load one-level BelongsTo / HasMany relations through the related
     * model's own policy, under the same context.
     *
     * @param list<string> $relations
     * @return $this
     */
    public function with(array $relations): static
    {
        foreach ($relations as $name) {
            if (str_contains($name, '.') || str_contains($name, ':')) {
                throw new UnsupportedProtectedOperation('Nested or column-constrained eager loads are not supported.');
            }

            $this->with[$name] = RelationShape::of($this->prototype, $name);
        }

        return $this;
    }

    // ----- execution ----------------------------------------------------------

    /** @return ProtectedCollection<int, TModel> */
    public function get(): ProtectedCollection
    {
        return $this->protect($this->query()->get()->all());
    }

    /** @return TModel|null */
    public function first(): ?Model
    {
        return (clone $this)->limit(1)->get()->first();
    }

    /** @return TModel */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw (new ModelNotFoundException())->setModel($this->prototype::class);
    }

    /** @return TModel|null */
    public function find(int|string $id): ?Model
    {
        $query = clone $this;
        $query->keys[] = [$this->qualify($this->prototype->getKeyName()), $id];

        return $query->first();
    }

    /**
     * A row that exists but is not visible is indistinguishable from one that
     * does not exist.
     *
     * @return TModel
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw (new ModelNotFoundException())->setModel($this->prototype::class, [$id]);
    }

    public function exists(): bool
    {
        return $this->query(shaped: false)->exists();
    }

    public function count(): int
    {
        return $this->query(shaped: false)->count();
    }

    public function sum(string $column): int|float
    {
        $sum = $this->query(shaped: false)->sum($this->qualify($column));

        return is_int($sum) || is_float($sum) ? $sum : (float) $sum;
    }

    /** @return LengthAwarePaginator<int, TModel> */
    public function paginate(int $perPage, int $page = 1): LengthAwarePaginator
    {
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new UnsupportedProtectedOperation(sprintf('perPage must be between 1 and %d.', self::MAX_PER_PAGE));
        }

        if ($this->limit !== null || $this->offset !== null) {
            throw new UnsupportedProtectedOperation('paginate() cannot be combined with limit() / offset().');
        }

        if ($page > intdiv(PHP_INT_MAX, $perPage)) {
            throw new UnsupportedProtectedOperation('The requested page is out of range.');
        }

        $paginator = $this->query()->paginate($perPage, $this->columns ?? [$this->qualify('*', false)], 'page', max(1, $page));

        return $paginator->setCollection($this->protect($paginator->getCollection()->all()));
    }

    /** @internal trusted key constraint used for relation paths; not part of the caller surface */
    public function constrainKey(string $column, int|string|null $value): static
    {
        $this->keys[] = [$this->qualify($column), $value];

        return $this;
    }

    public function __clone()
    {
        $this->filters = clone $this->filters;
    }

    /**
     * @param bool $shaped apply select / order / limit / offset / eager loads (false for aggregates)
     * @return EloquentBuilder<TModel>
     */
    private function query(bool $shaped = true): EloquentBuilder
    {
        /** @var EloquentBuilder<TModel> $query */
        // Without the model's own $with: those loads would go around the related
        // model's policy. (A model-level $withCount is refused by GuardAudit.)
        $query = $this->prototype->newQueryWithoutRelationships();
        $base = $query->getQuery();
        $compiler = new PredicateCompiler();

        $compiler->apply($base, $this->privacy, $this->prototype->getTable());

        foreach ($this->keys as [$column, $value]) {
            // A NULL key matches nothing; never "IS NULL".
            $value === null ? $base->whereRaw('0 = 1') : $base->where($column, '=', $value);
        }

        if (! $this->filters->isEmpty()) {
            $base->where(fn (QueryBuilder $group) => $this->filters->applyTo($group));
        }

        if (! $shaped) {
            return $query;
        }

        if ($this->columns !== null) {
            // An eager load matches on these; without them every row would read
            // as "no visible parent", which is a different answer.
            $keys = array_map(fn (RelationShape $shape): string => $this->qualify($shape->parentColumn), array_values($this->with));

            $query->select(array_values(array_unique([...$this->columns, ...$keys])));
        }

        foreach ($this->orders as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        if ($this->offset !== null) {
            $query->offset($this->offset);
        }

        foreach ($this->with as $name => $shape) {
            $related = new ($shape->related)();
            $predicate = (new PolicyResolver())->resolveRead($shape->related, $this->context)->toPredicate();

            $query->with([$name => static function (Relation $relation) use ($compiler, $predicate, $related): void {
                $relation->getQuery()->setEagerLoads([]);
                $compiler->apply($relation->getQuery()->getQuery(), $predicate, $related->getTable());
            }]);
        }

        return $query;
    }

    /**
     * @param array<int, TModel> $models
     * @return ProtectedCollection<int, TModel>
     */
    private function protect(array $models): ProtectedCollection
    {
        foreach ($models as $model) {
            $this->seal($model);
        }

        return new ProtectedCollection(array_values($models));
    }

    /**
     * Protection is closed under reachability: every model reachable through a
     * loaded relation is bound to the same context, and every loaded collection
     * becomes a ProtectedCollection, so nothing reachable can be reloaded or
     * queried around its guards.
     */
    private function seal(Model $model): void
    {
        $this->bind($model);

        foreach ($model->getRelations() as $name => $loaded) {
            if ($loaded instanceof Model) {
                $this->seal($loaded);
            } elseif ($loaded instanceof EloquentCollection) {
                foreach ($loaded as $related) {
                    $this->seal($related);
                }

                $model->setRelation($name, new ProtectedCollection($loaded->all()));
            }
        }
    }

    private function bind(Model $model): void
    {
        if (! method_exists($model, 'bindPrivacyContext')) {
            throw new UnsupportedProtectedOperation(sprintf(
                '%s must use the HasPrivacyPolicy trait: returned models could not be guarded otherwise.',
                $model::class,
            ));
        }

        $model->bindPrivacyContext($this->context);
    }

    private function qualify(string $column, bool $validate = true): string
    {
        return $validate
            ? Identifier::column($column, $this->prototype->getTable())
            : $this->prototype->getTable().'.'.$column;
    }
}
