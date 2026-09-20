<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Concerns;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingAttribute;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use BWH\EloquentPrivacyPolicy\Query\RelationShape;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Adds the protected entry point to an existing model and guards instances
 * that a protected builder returned. Instances loaded any other way behave
 * exactly as before.
 *
 * The policy comes from Privacy::register() or from a static privacyPolicy()
 * method on the model.
 *
 * @mixin Model
 */
trait HasPrivacyPolicy
{
    /**
     * Set on instances returned by a protected builder. A plain property: never
     * an attribute, never static. Protected rather than private so that the
     * framework's __sleep(), which runs in the parent's scope, keeps it: a
     * serialize round trip must not hand back an unguarded copy.
     */
    protected ?PrivacyContext $privacyContext = null;

    /** @return ProtectedBuilder<static> */
    public static function privacyQuery(?PrivacyContext $context): ProtectedBuilder
    {
        return ProtectedBuilder::for(static::class, $context);
    }

    public function isPrivacyProtected(): bool
    {
        return $this->privacyContext !== null;
    }

    public function privacyContext(): ?PrivacyContext
    {
        return $this->privacyContext;
    }

    /** @internal called by the protected builder on freshly hydrated instances */
    public function bindPrivacyContext(PrivacyContext $context): static
    {
        if ($this->privacyContext !== null && $this->privacyContext !== $context) {
            throw new UnsupportedProtectedOperation('A protected model cannot be re-bound to another context.');
        }

        $this->privacyContext = $context;

        return $this;
    }

    /**
     * The supported way to query a relation of a protected instance: a
     * protected builder rooted at the related model, constrained by the key.
     *
     * @return ProtectedBuilder<Model>
     */
    public function privacyRelation(string $name): ProtectedBuilder
    {
        if ($this->privacyContext === null) {
            throw new UnsupportedProtectedOperation('privacyRelation() is only available on protected instances.');
        }

        $shape = RelationShape::of($this, $name);

        if (! array_key_exists($shape->parentColumn, $this->getAttributes())) {
            throw new MissingAttribute(sprintf(
                'Key column "%s" was not selected; relation "%s" cannot be resolved.',
                $shape->parentColumn,
                $name,
            ));
        }

        $key = $this->getAttributes()[$shape->parentColumn];

        return ProtectedBuilder::for($shape->related, $this->privacyContext)
            ->constrainKey($shape->relatedColumn, is_int($key) || is_string($key) ? $key : null);
    }

    // ----- guards: relations ---------------------------------------------------

    public function getRelationValue($key)
    {
        if ($this->privacyContext === null) {
            return parent::getRelationValue($key);
        }

        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (! $this->isRelation($key)) {
            return null;
        }

        if ($this->preventsLazyLoading) {
            $this->handleLazyLoadingViolation($key);
        }

        // Lazy loads go through the related model's policy; framework relation
        // autoloading is deliberately not consulted.
        $shape = RelationShape::of($this, $key);
        $query = $this->privacyRelation($key);

        $this->setRelation($key, $shape->many ? $query->get() : $query->first());

        return $this->relations[$key];
    }

    protected function newRelatedInstance($class)
    {
        $this->rejectWhenProtected('Calling a relation method');

        return parent::newRelatedInstance($class);
    }

    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        $this->rejectWhenProtected('Calling a relation method');

        return parent::newMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    // ----- guards: reloads -------------------------------------------------------

    public function refresh()
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::refresh();
    }

    /** @param array<array-key, mixed>|string $with */
    public function fresh($with = [])
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::fresh($with);
    }

    /** @param array<array-key, mixed>|string $relations */
    public function load($relations)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::load(...func_get_args());
    }

    /** @param array<array-key, mixed>|string $relations */
    public function loadMissing($relations)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::loadMissing(...func_get_args());
    }

    /** @param array<array-key, mixed>|string $relations */
    public function loadAggregate($relations, $column, $function = null)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::loadAggregate($relations, $column, $function);
    }

    /** @param array<array-key, mixed> $relations */
    public function loadMorph($relation, $relations)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::loadMorph($relation, $relations);
    }

    /** @param array<array-key, mixed> $relations */
    public function loadMorphAggregate($relation, $relations, $column, $function = null)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::loadMorphAggregate($relation, $relations, $column, $function);
    }

    // ----- guards: writes --------------------------------------------------------
    // update, push, touch, restore, saveQuietly and friends funnel into save();
    // deleteQuietly and forceDelete funnel into delete().

    public function save(array $options = [])
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::save($options);
    }

    // A copy is a new, unbound instance carrying the same keys: its relations
    // would load around every policy, and saving it would write from a read.
    /** @param list<string>|null $except */
    public function replicate(?array $except = null)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::replicate($except);
    }

    // Guarded on its own: without timestamps touch() returns before it reaches save().
    /** @param array<array-key, mixed>|string|null $attribute */
    public function touch($attribute = null)
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::touch($attribute);
    }

    public function delete()
    {
        $this->rejectWhenProtected(__FUNCTION__.'()');

        return parent::delete();
    }

    /** @param array<array-key, mixed> $extra */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $this->rejectWhenProtected($method.'()');

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method)
    {
        $this->rejectWhenProtected($method.'()');

        return parent::incrementOrDecrementEach($columns, $extra, $method);
    }

    private function rejectWhenProtected(string $what): void
    {
        if ($this->privacyContext !== null) {
            throw new UnsupportedProtectedOperation(sprintf(
                '%s is not supported on a protected %s. A read authorises neither a write nor an unguarded reload; '
                .'use a protected builder, privacyRelation(), or an action.',
                $what,
                static::class,
            ));
        }
    }
}
