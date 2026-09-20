<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use BWH\EloquentPrivacyPolicy\Query\ProtectedCollection;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use Error;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Deliberate attempts to get out of the protected API using nothing but its
 * public surface. Section 1 excludes one thing only: application code that
 * holds database credentials can always run an ordinary, unprotected query.
 * Everything else that reaches hidden rows is a bypass.
 */
final class EscapeRouteTest extends FixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createClinicalSchema();
        $this->createQaSchema();
        ClinicalSeed::seed();
        QaSeed::seed();
    }

    // ----- the builder never hands out a native builder ---------------------

    public function test_no_public_method_of_the_protected_surface_returns_a_builder(): void
    {
        $leaky = [EloquentBuilder::class, QueryBuilder::class, Relation::class];

        foreach ([ProtectedBuilder::class, FilterGroup::class, ProtectedCollection::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if (! $method->isPublic() || $method->isStatic()) {
                    continue;
                }

                foreach ($this->namesOf($method->getReturnType()) as $returned) {
                    foreach ($leaky as $forbidden) {
                        $this->assertFalse(
                            $returned === $forbidden || is_subclass_of($returned, $forbidden),
                            sprintf('%s::%s() returns %s', $class, $method->getName(), $returned),
                        );
                    }
                }
            }
        }
    }

    public function test_the_protected_builder_has_no_call_forwarding_and_no_query_accessor(): void
    {
        $reflection = new ReflectionClass(ProtectedBuilder::class);

        foreach (['__call', '__callStatic', '__get', '__set', '__invoke', 'toBase', 'getQuery', 'getModel', 'getConnection', 'macro', 'mixin'] as $method) {
            $this->assertFalse($reflection->hasMethod($method), sprintf('ProtectedBuilder::%s() exists', $method));
        }

        foreach ($reflection->getProperties() as $property) {
            $this->assertFalse($property->isPublic(), sprintf('ProtectedBuilder::$%s is public', $property->getName()));
        }

        $this->assertTrue($reflection->isFinal());
    }

    public function test_an_eloquent_macro_is_not_reachable_through_the_protected_builder(): void
    {
        EloquentBuilder::macro('privacyEscapeHatch', fn (): string => 'reached');
        QueryBuilder::macro('privacyEscapeHatch', fn (): string => 'reached');

        try {
            // The macro really is live on an ordinary builder.
            $this->assertSame('reached', $this->callLoosely(ClinicalRecord::query(), 'privacyEscapeHatch'));

            $query = ClinicalRecord::privacyQuery($this->context(1));

            // The builder declares no __call (asserted structurally above), so
            // the macro is simply not there.
            try {
                $this->callLoosely($query, 'privacyEscapeHatch');
                $this->fail('A macro was forwarded through the protected builder.');
            } catch (Error $error) {
                $this->assertStringContainsString('privacyEscapeHatch', $error->getMessage());
            }
        } finally {
            foreach ([EloquentBuilder::class, QueryBuilder::class] as $class) {
                (new ReflectionClass($class))->setStaticPropertyValue('macros', []);
            }
        }

    }

    // ----- the collection ----------------------------------------------------

    public function test_collection_transformations_keep_the_models_protected(): void
    {
        $records = ClinicalRecord::privacyQuery($this->context(1))->orderBy('id')->get();

        $derived = [
            'filter' => $records->filter(static fn (ClinicalRecord $r): bool => true),
            'map' => $records->map(static fn (ClinicalRecord $r): ClinicalRecord => $r),
            'reverse' => $records->reverse(),
            'sortBy' => $records->sortBy('id'),
            'slice' => $records->slice(0),
            'values' => $records->values(),
            'unique' => $records->unique(),
            'merge' => $records->merge([]),
        ];

        foreach ($derived as $label => $collection) {
            $this->assertInstanceOf(ProtectedCollection::class, $collection, $label);

            foreach ($collection as $model) {
                $this->assertTrue($model->isPrivacyProtected(), $label);
            }
        }

        // Higher-order proxies go through the instance guards like any access.
        $parents = $records->map->patient;

        foreach ($parents as $parent) {
            $this->assertTrue($parent === null || $parent->isPrivacyProtected());
        }

        // toBase() drops to a plain collection but of the same protected models.
        foreach ($records->toBase() as $model) {
            $this->assertTrue($model->isPrivacyProtected());
        }
    }

    public function test_collection_level_chain_loading_is_refused_too(): void
    {
        $records = ClinicalRecord::privacyQuery($this->context(1))->get();

        $this->expectException(UnsupportedProtectedOperation::class);
        $this->callLoosely($records, 'loadMissingRelationshipChain', [[['patient', ClinicalRecord::class]]]);
    }

    // ----- model-level reconstruction ---------------------------------------

    public function test_cloning_a_protected_model_keeps_it_protected(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);
        $clone = clone $record;

        $this->assertTrue($clone->isPrivacyProtected());

        $this->expectException(UnsupportedProtectedOperation::class);
        $clone->delete();
    }

    /**
     * Model::__sleep() builds its key list with get_object_vars($this) from the
     * Model scope, so $privacyContext — private to the concrete class through
     * the trait — is not visible and is dropped. The restored instance has the
     * same key, the same attributes and exists = true, but no guards, so its
     * relations answer from outside the policy.
     *
     * Section 5.3 says a queue-restored model is an ordinary model, which is
     * about not trusting a serialised decision; whether an in-process
     * serialize/unserialize round trip may silently remove the guards is not
     * settled there. This asserts the conservative reading.
     */
    public function test_a_serialize_round_trip_does_not_strip_the_guards(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->findOrFail(1);

        $this->assertSame([1, 10, 11], $patient->records->modelKeys());

        /** @var Patient $restored */
        $restored = unserialize(serialize($patient));

        $this->assertSame($patient->getKey(), $restored->getKey());
        $this->assertTrue(
            $restored->isPrivacyProtected(),
            'serialize()/unserialize() removed the protected-instance guards',
        );
        $this->assertNotContains(
            2,
            $restored->records->modelKeys(),
            'the restored model read a row the policy denies (record 2 is embargoed)',
        );
    }

    public function test_replicate_and_new_instance_do_not_carry_the_context(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        // Both produce a *new, unsaved* row rather than a handle on this one,
        // so they are ordinary models; what matters is that they hold no key.
        $replica = $record->replicate();
        $fresh = $record->newInstance();

        $this->assertFalse($replica->exists);
        $this->assertNull($replica->getKey());
        $this->assertFalse($fresh->exists);
        $this->assertNull($fresh->getKey());
        $this->assertFalse($replica->isPrivacyProtected());
        $this->assertFalse($fresh->isPrivacyProtected());
    }

    public function test_set_relation_only_ever_shows_the_caller_what_it_supplied(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);
        $smuggled = Patient::query()->findOrFail(2); // ordinary, unprotected

        $record->setRelation('patient', $smuggled);

        $this->assertSame(2, $record->patient?->getKey(), 'the caller gets back exactly what it put in');
        $this->assertArrayHasKey('patient', $record->getRelations());
        $this->assertSame([2], array_column([$record->relationsToArray()['patient']], 'id'));

        // Unsetting it puts the guarded path back.
        $record->unsetRelation('patient');
        $reloaded = $record->patient;

        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->getKey());
        $this->assertTrue($reloaded->isPrivacyProtected());
    }

    /**
     * Section 1 excludes this explicitly: an ordinary query is always
     * available to application code. The test pins the boundary so a future
     * change that made newQuery() *protected* would be noticed, and so the
     * report can distinguish it from a real bypass.
     */
    public function test_new_query_on_a_protected_instance_is_an_ordinary_query_by_design(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $ordinary = $record->newQuery();

        $this->assertInstanceOf(EloquentBuilder::class, $ordinary);
        $this->assertSame(ClinicalRecord::query()->count(), $ordinary->count());
        $this->assertInstanceOf(EloquentCollection::class, $ordinary->get());
        $this->assertNotInstanceOf(ProtectedCollection::class, $ordinary->get());

        foreach ($ordinary->get() as $model) {
            $this->assertFalse($model->isPrivacyProtected());
        }
    }

    // ----- nothing static remembers anything --------------------------------

    public function test_no_static_property_of_the_package_retains_a_context_facts_or_a_model(): void
    {
        $one = $this->qa(1);
        $two = $this->qa(2);

        $this->assertSame(QaSeed::VISIBLE_TO_1, Question::privacyQuery($one)->orderBy('id')->get()->modelKeys());
        $this->assertNotSame(
            QaSeed::VISIBLE_TO_1,
            Question::privacyQuery($two)->orderBy('id')->get()->modelKeys(),
        );

        foreach ($this->packageClasses() as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if (! $property->isStatic()) {
                    continue;
                }

                $this->assertNoContextWithin(
                    $property->getValue(),
                    sprintf('%s::$%s', $class, $property->getName()),
                );
            }
        }
    }

    public function test_the_registry_holds_only_context_free_definitions(): void
    {
        Question::privacyQuery($this->qa(1))->get();

        $reflection = new ReflectionClass(Privacy::class);
        $policies = $reflection->getProperty('policies')->getValue();

        $this->assertIsArray($policies);
        $this->assertNotSame([], $policies);

        foreach ($policies as $policy) {
            $this->assertInstanceOf(ModelPolicy::class, $policy);
            $this->assertNoContextWithin($policy, 'a cached ModelPolicy');
        }
    }

    public function test_two_contexts_back_to_back_see_only_their_own_rows(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $first = ClinicalRecord::privacyQuery($this->context(1))->orderBy('id')->get();
            $second = ClinicalRecord::privacyQuery($this->context(2))->orderBy('id')->get();

            $this->assertSame(ClinicalSeed::RECORDS_VISIBLE_TO_1, $first->modelKeys());
            $this->assertSame(ClinicalSeed::RECORDS_VISIBLE_TO_2, $second->modelKeys());

            foreach ($first as $model) {
                $this->assertSame(1, $model->privacyContext()?->viewer->id());
            }

            foreach ($second as $model) {
                $this->assertSame(2, $model->privacyContext()?->viewer->id());
            }
        }
    }

    // ----- helpers -----------------------------------------------------------

    /** @return list<string> */
    private function namesOf(mixed $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_merge(...array_map($this->namesOf(...), $type->getTypes()));
        }

        return [];
    }

    private function assertNoContextWithin(mixed $value, string $where, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoContextWithin($item, $where, $depth + 1);
            }

            return;
        }

        if (! is_object($value)) {
            return;
        }

        foreach ([PrivacyContext::class, Model::class, \BWH\EloquentPrivacyPolicy\Context\Facts::class] as $forbidden) {
            $this->assertNotInstanceOf($forbidden, $value, $where.' retains a '.$forbidden);
        }

        foreach ((new ReflectionClass($value))->getProperties() as $property) {
            $this->assertNoContextWithin($property->getValue($value), $where, $depth + 1);
        }
    }

    /** @return list<class-string> every package class except the concurrently authored Action namespace */
    private function packageClasses(): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2).'/src';

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Action/')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'BWH\\EloquentPrivacyPolicy\\'.str_replace('/', '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    private function qa(int $viewer): PrivacyContext
    {
        return $this->context(
            $viewer,
            ['is_moderator' => false, 'eligible_organization_ids' => [10]],
            ['workspace_id' => 1],
        );
    }
}
