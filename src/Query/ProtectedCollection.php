<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Query;

use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Collection of protected models. Collection-level loading would query
 * relations without going through the models' guards, so it is rejected.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
final class ProtectedCollection extends Collection
{
    public function load($relations)
    {
        throw self::rejected(__FUNCTION__);
    }

    public function loadMissing($relations)
    {
        throw self::rejected(__FUNCTION__);
    }

    public function loadAggregate($relations, $column, $function = null)
    {
        throw self::rejected(__FUNCTION__);
    }

    public function loadMorph($relation, $relations)
    {
        throw self::rejected(__FUNCTION__);
    }

    public function loadMorphCount($relation, $relations)
    {
        throw self::rejected(__FUNCTION__);
    }

    public function fresh($with = [])
    {
        throw self::rejected(__FUNCTION__);
    }

    public function toQuery()
    {
        throw self::rejected(__FUNCTION__);
    }

    public function withRelationshipAutoloading()
    {
        throw self::rejected(__FUNCTION__);
    }

    private static function rejected(string $method): UnsupportedProtectedOperation
    {
        return new UnsupportedProtectedOperation(sprintf(
            'Collection::%s() is not supported on protected models; query through a protected builder.',
            $method,
        ));
    }
}
