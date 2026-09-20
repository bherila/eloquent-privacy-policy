<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Query;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;

/**
 * The instance guards live in a trait, and a method declared on the model class
 * itself silently wins over a trait method. Fail closed when that happened.
 */
final class GuardAudit
{
    public const array GUARDED = [
        'getRelationValue', 'newRelatedInstance', 'newMorphTo',
        'save', 'touch', 'delete', 'replicate', 'incrementOrDecrement', 'incrementOrDecrementEach',
        'refresh', 'fresh', 'load', 'loadMissing', 'loadAggregate', 'loadMorph', 'loadMorphAggregate',
    ];

    /** @var array<class-string<Model>, list<string>> class => overridden guard methods (definitions only) */
    private static array $audited = [];

    /**
     * @param class-string<Model> $model
     * @return list<string> guard methods that do not come from the trait
     */
    public static function overridden(string $model): array
    {
        if (isset(self::$audited[$model])) {
            return self::$audited[$model];
        }

        $traitFile = (new ReflectionClass(HasPrivacyPolicy::class))->getFileName();
        $overridden = [];

        foreach (self::GUARDED as $method) {
            if ((new ReflectionMethod($model, $method))->getFileName() !== $traitFile) {
                $overridden[] = $method;
            }
        }

        return self::$audited[$model] = $overridden;
    }

    /** @param class-string<Model> $model */
    public static function assertIntact(string $model): void
    {
        // A model-level $withCount adds an aggregate over related rows to every
        // query, counting rows the viewer may not see.
        if (((new ReflectionClass($model))->getDefaultProperties()['withCount'] ?? []) !== []) {
            throw new UnsupportedProtectedOperation(sprintf(
                '%s declares a model-level $withCount, which would count related rows around their policy.',
                $model,
            ));
        }

        $overridden = self::overridden($model);

        if ($overridden !== []) {
            throw new UnsupportedProtectedOperation(sprintf(
                '%s overrides guarded method(s) %s, which disables the protected-instance guards.',
                $model,
                implode(', ', $overridden),
            ));
        }
    }
}
