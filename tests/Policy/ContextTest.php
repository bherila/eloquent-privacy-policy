<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Context\Capacity;
use BWH\EloquentPrivacyPolicy\Context\CredentialRestrictions;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingContext;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** Contract section 3. */
final class ContextTest extends FixtureTestCase
{
    // ----- scalar snapshots --------------------------------------------------

    public function test_objects_are_rejected_in_facts_and_in_the_resource_scope(): void
    {
        $objects = [
            'plain' => new stdClass(),
            'model' => new Question(),
            'date' => new DateTimeImmutable(),
            'closure' => static fn (): int => 1,
        ];

        foreach ($objects as $label => $value) {
            foreach ([Facts::class, ResourceScope::class] as $bag) {
                try {
                    new $bag(['k' => $value]);
                    $this->fail(sprintf('%s accepted a %s.', $bag, $label));
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('scalar snapshot', $exception->getMessage());
                }
            }
        }
    }

    public function test_objects_inside_a_list_valued_fact_are_rejected_too(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Facts(['ids' => [1, 2, new stdClass()]]);
    }

    public function test_nested_arrays_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Facts(['ids' => [[1, 2]]]);
    }

    // ----- absent is not null ------------------------------------------------

    public function test_an_absent_fact_is_not_a_null_fact(): void
    {
        $facts = new Facts(['present' => null]);

        $this->assertTrue($facts->has('present'));
        $this->assertNull($facts->get('present'));

        $this->assertFalse($facts->has('absent'));

        $this->expectException(MissingFact::class);
        $facts->get('absent');
    }

    public function test_a_null_fact_is_still_not_a_boolean_or_an_integer(): void
    {
        $facts = new Facts(['flag' => null, 'n' => null, 'list' => null]);

        foreach ([fn () => $facts->bool('flag'), fn () => $facts->int('n'), fn () => $facts->ints('list')] as $read) {
            try {
                $read();
                $this->fail('A null fact was read as a typed value.');
            } catch (MissingFact) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_absent_scope_key_is_a_missing_fact_and_fails_the_stage(): void
    {
        $scope = new ResourceScope(['tenant_id' => 7]);

        $this->assertSame(7, $scope->int('tenant_id'));
        $this->expectException(MissingFact::class);
        $scope->int('other_id');
    }

    public function test_an_empty_list_fact_is_a_value_not_an_absence(): void
    {
        $facts = new Facts(['ids' => []]);

        $this->assertTrue($facts->has('ids'));
        $this->assertSame([], $facts->ints('ids'));
    }

    // ----- anonymous is not missing -----------------------------------------

    public function test_a_null_context_is_a_missing_context_not_an_anonymous_one(): void
    {
        $this->expectException(MissingContext::class);

        Question::privacyQuery(null);
    }

    public function test_privacy_query_through_the_registry_also_rejects_a_null_context(): void
    {
        $this->expectException(MissingContext::class);

        Privacy::query(Question::class, null);
    }

    public function test_an_anonymous_viewer_is_denied_without_any_rule_running(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $ran = false;

        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()->grant(Rule::of('explodes', function () use (&$ran): Predicate {
                $ran = true;

                throw new RuntimeException('a rule ran for an anonymous viewer');
            })),
        ));

        $this->assertSame([], Question::privacyQuery($this->context(null))->get()->all());
        $this->assertFalse($ran, 'no rule may run for an anonymous viewer on a private policy');
    }

    public function test_a_policy_declared_allow_anonymous_may_admit_an_anonymous_viewer(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->allowAnonymous()
                ->grant(Rule::of('public_faq', fn (PrivacyContext $c) => $c->viewer->isAnonymous()
                    ? Col::bool('is_faq')->eq(true)
                    : Predicate::always())),
        ));

        $this->assertSame([2, 9], Question::privacyQuery($this->context(null))->orderBy('id')->get()->modelKeys());
    }

    public function test_viewer_id_on_an_anonymous_viewer_throws(): void
    {
        $viewer = Viewer::anonymous();

        $this->assertTrue($viewer->isAnonymous());
        $this->assertSame('anonymous', $viewer->type);

        $this->expectException(MissingFact::class);
        $viewer->id();
    }

    public function test_an_identified_viewer_keeps_its_scalar_id_and_type(): void
    {
        $this->assertSame(7, Viewer::identified(7)->id());
        $this->assertSame('user', Viewer::identified(7)->type);
        $this->assertSame('service', Viewer::identified('svc-1', 'service')->type);
        $this->assertSame(42, Viewer::identified('42')->intId());

        $this->expectException(MissingFact::class);
        Viewer::identified('not-a-number')->intId();
    }

    // ----- the clock ---------------------------------------------------------

    public function test_now_is_utc_and_truncated_to_whole_seconds(): void
    {
        // +05:45, a non-hour offset, with microseconds.
        $local = new DateTimeImmutable('2026-06-01 18:19:56.789012', new DateTimeZone('Asia/Kathmandu'));

        $context = new PrivacyContext(Viewer::identified(1), new Operation('t'), now: $local);

        $this->assertSame('UTC', $context->now->getTimezone()->getName());
        $this->assertSame('2026-06-01 12:34:56', $context->now->format('Y-m-d H:i:s'));
        $this->assertSame('000000', $context->now->format('u'));
        $this->assertSame($local->getTimestamp(), $context->now->getTimestamp());
    }

    public function test_a_non_hour_negative_offset_is_also_normalised(): void
    {
        $local = new DateTimeImmutable('2026-01-15 07:08:09.999999', new DateTimeZone('America/St_Johns'));

        $context = new PrivacyContext(Viewer::identified(1), new Operation('t'), now: $local);

        $this->assertSame('UTC', $context->now->getTimezone()->getName());
        $this->assertSame('000000', $context->now->format('u'));
        $this->assertSame('2026-01-15 10:38:09', $context->now->format('Y-m-d H:i:s'));
    }

    public function test_a_default_context_still_has_a_utc_whole_second_clock(): void
    {
        $context = new PrivacyContext(Viewer::identified(1), new Operation('t'));

        $this->assertSame('UTC', $context->now->getTimezone()->getName());
        $this->assertSame('000000', $context->now->format('u'));
    }

    // ----- the rest of the value object -------------------------------------

    public function test_an_operation_needs_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Operation('');
    }

    public function test_defaults_are_empty_rather_than_absent(): void
    {
        $context = new PrivacyContext(Viewer::identified(1), new Operation('t'));

        $this->assertSame([], $context->facts->all());
        $this->assertSame([], $context->scope->all());
        $this->assertTrue($context->restrictions->isUnrestricted());
        $this->assertNull($context->capacity);
    }

    public function test_credential_restrictions_narrow_and_never_expand(): void
    {
        $restricted = CredentialRestrictions::only(['record.read']);

        $this->assertTrue($restricted->permits('record.read'));
        $this->assertFalse($restricted->permits('record.write'));
        $this->assertFalse($restricted->isUnrestricted());
        $this->assertTrue(CredentialRestrictions::unrestricted()->permits('anything'));
    }

    public function test_capacity_is_a_scalar_pair_and_not_an_identity(): void
    {
        $capacity = new Capacity('organization', 7);

        $context = new PrivacyContext(Viewer::identified(1), new Operation('t'), capacity: $capacity);

        $this->assertNotNull($context->capacity);
        $this->assertSame('organization', $context->capacity->kind);
        $this->assertSame(7, $context->capacity->id);
    }

    public function test_deriving_a_context_keeps_the_clock_and_adds_facts_without_mutating(): void
    {
        $original = $this->context(1, ['a' => 1]);
        $derived = $original->withFacts(['b' => 2]);

        $this->assertSame(['a' => 1], $original->facts->all());
        $this->assertSame(['a' => 1, 'b' => 2], $derived->facts->all());
        $this->assertEquals($original->now, $derived->now);
        $this->assertSame('fixture.list', $original->operation->name);
        $this->assertSame('other.op', $original->forOperation('other.op')->operation->name);
    }
}
