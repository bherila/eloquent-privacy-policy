<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyNotRegistered;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry of policy definitions and the explicit entry point. Only
 * context-free definitions are held statically; contexts, facts, decisions and
 * models never are.
 */
final class Privacy
{
    /** @var array<class-string<Model>, ModelPolicy|Closure(): ModelPolicy> */
    private static array $policies = [];

    /**
     * @param class-string<Model> $model
     * @param ModelPolicy|Closure(): ModelPolicy $policy
     */
    public static function register(string $model, ModelPolicy|Closure $policy): void
    {
        self::$policies[$model] = $policy;
    }

    /** @param class-string<Model> $model */
    public static function hasPolicy(string $model): bool
    {
        return isset(self::$policies[$model]) || method_exists($model, 'privacyPolicy');
    }

    /** @param class-string<Model> $model */
    public static function policyFor(string $model): ModelPolicy
    {
        $policy = self::$policies[$model] ?? null;

        if ($policy === null && method_exists($model, 'privacyPolicy')) {
            $policy = $model::privacyPolicy();
        }

        if ($policy instanceof Closure) {
            $policy = $policy();
        }

        if (! $policy instanceof ModelPolicy) {
            throw new PolicyNotRegistered(sprintf('%s has no privacy policy.', $model));
        }

        return self::$policies[$model] = $policy;
    }

    /**
     * @template TModel of Model
     *
     * @param class-string<TModel> $model a model using {@see HasPrivacyPolicy}
     * @return ProtectedBuilder<TModel>
     */
    public static function query(string $model, ?PrivacyContext $context): ProtectedBuilder
    {
        return ProtectedBuilder::for($model, $context);
    }

    /** Forget every registered definition. For tests. */
    public static function flush(): void
    {
        self::$policies = [];
    }
}
