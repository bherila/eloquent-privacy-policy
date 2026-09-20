<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Query;

use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The key structure of a supported relation, read from the model's own relation
 * definition on an unprotected probe instance. Only plain BelongsTo and HasMany
 * to a model that has a policy are supported.
 */
final readonly class RelationShape
{
    /**
     * @param class-string<Model> $related
     * @param string $parentColumn column on the model that defines the relation
     * @param string $relatedColumn column on the related model
     */
    private function __construct(
        public string $name,
        public bool $many,
        public string $related,
        public string $parentColumn,
        public string $relatedColumn,
    ) {
    }

    public static function of(Model $model, string $name): self
    {
        $probe = $model->newInstance();

        if (! method_exists($probe, $name)) {
            throw new UnsupportedProtectedOperation(sprintf('%s has no relation "%s".', $model::class, $name));
        }

        $relation = Relation::noConstraints(static fn () => $probe->{$name}());

        // Exact classes: MorphTo extends BelongsTo and must not slip through.
        $shape = match (true) {
            $relation instanceof Relation && $relation::class === BelongsTo::class => new self(
                $name,
                false,
                $relation->getRelated()::class,
                $relation->getForeignKeyName(),
                $relation->getOwnerKeyName(),
            ),
            $relation instanceof Relation && $relation::class === HasMany::class => new self(
                $name,
                true,
                $relation->getRelated()::class,
                $relation->getLocalKeyName(),
                $relation->getForeignKeyName(),
            ),
            default => throw new UnsupportedProtectedOperation(sprintf(
                'Relation %s::%s() is not a plain BelongsTo or HasMany; it is not supported on the protected path.',
                $model::class,
                $name,
            )),
        };

        if ($relation->getQuery()->getQuery()->wheres !== []) {
            throw new UnsupportedProtectedOperation(sprintf(
                'Relation %s::%s() adds its own constraints, which the protected path does not carry yet.',
                $model::class,
                $name,
            ));
        }

        GuardAudit::assertIntact($shape->related);

        if (! Privacy::hasPolicy($shape->related)) {
            throw new UnsupportedProtectedOperation(sprintf(
                'Relation %s::%s() leads to %s, which has no privacy policy.',
                $model::class,
                $name,
                $shape->related,
            ));
        }

        return $shape;
    }
}
