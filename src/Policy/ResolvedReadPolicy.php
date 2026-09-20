<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Runtime\ExistsFacts;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;

/**
 * A read rule set resolved against one context: per-stage predicates, fully
 * expanded. The single input of both interpreters. Operation-local.
 */
final readonly class ResolvedReadPolicy
{
    /**
     * @param array<string, array<string, Predicate>> $stages stage value => rule id => predicate, for resolved stages only
     */
    public function __construct(
        private array $stages,
        private Decision $terminal,
    ) {
    }

    /** A policy that denies every row, e.g. an anonymous viewer on a private policy. */
    public static function denyAll(): self
    {
        return new self([], Decision::Deny);
    }

    /**
     * visible = H AND ( P OR ( NOT D AND ( A OR T ) ) )
     *
     * Privileged grants are not flattened into ordinary grants, and a terminal
     * Allow bypasses neither D nor H.
     */
    public function toPredicate(): Predicate
    {
        return Predicate::all(
            ...array_values($this->of(Stage::Mandatory)),
            ...[Predicate::any(
                ...array_values($this->of(Stage::Privileged)),
                ...[Predicate::all(
                    Predicate::not(Predicate::any(...array_values($this->of(Stage::Deny)))),
                    Predicate::any(
                        ...array_values($this->of(Stage::Grant)),
                        ...[Predicate::constant($this->terminal === Decision::Allow)],
                    ),
                )],
            )],
        );
    }

    /** Runtime decision for one row, reduced stage by stage exactly like any other rule set. */
    public function decide(RowSnapshot $row, ExistsFacts $facts, PredicateEvaluator $evaluator, StageReducer $reducer): Decision
    {
        $rules = RuleSet::define()->terminal($this->terminal)->allowAnonymous();

        foreach (Stage::cases() as $stage) {
            foreach ($this->of($stage) as $id => $predicate) {
                $rule = Rule::of($id, static fn (): Predicate => $predicate);

                $rules = match ($stage) {
                    Stage::Mandatory => $rules->mandatory($rule),
                    Stage::Privileged => $rules->privileged($rule),
                    Stage::Deny => $rules->deny($rule),
                    Stage::Grant => $rules->grant($rule),
                };
            }
        }

        return $reducer->reduce(
            $rules,
            fn (PredicateRule|ActionRule $rule, Stage $stage): Decision => $stage->outcomeFor(
                $evaluator->evaluate($this->stages[$stage->value][$rule->id()], $row, $facts),
            ),
        );
    }

    /** @return array<string, Predicate> */
    private function of(Stage $stage): array
    {
        return $this->stages[$stage->value] ?? [];
    }
}
