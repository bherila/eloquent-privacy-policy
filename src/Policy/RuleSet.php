<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use InvalidArgumentException;

/**
 * Immutable, fluent definition of the five stages. Context-free, so it may be
 * shared and cached; nothing permission-bearing lives here.
 */
final readonly class RuleSet
{
    /**
     * @param array<string, array<string, PredicateRule|ActionRule>> $rules stage value => rule id => rule
     */
    private function __construct(
        private array $rules,
        public Decision $terminal,
        public bool $allowsAnonymous,
    ) {
    }

    public static function define(): self
    {
        return new self([], Decision::Deny, false);
    }

    public function mandatory(PredicateRule|ActionRule ...$rules): self
    {
        return $this->add(Stage::Mandatory, $rules);
    }

    public function privileged(PredicateRule|ActionRule ...$rules): self
    {
        return $this->add(Stage::Privileged, $rules);
    }

    public function deny(PredicateRule|ActionRule ...$rules): self
    {
        return $this->add(Stage::Deny, $rules);
    }

    public function grant(PredicateRule|ActionRule ...$rules): self
    {
        return $this->add(Stage::Grant, $rules);
    }

    public function terminal(Decision $decision): self
    {
        if ($decision === Decision::Skip) {
            throw new InvalidArgumentException('The terminal rule must be an explicit Allow or Deny.');
        }

        return new self($this->rules, $decision, $this->allowsAnonymous);
    }

    /** Without this, an anonymous viewer is denied before any rule runs. */
    public function allowAnonymous(): self
    {
        return new self($this->rules, $this->terminal, true);
    }

    /** @return array<string, PredicateRule|ActionRule> keyed by rule id */
    public function rulesOf(Stage $stage): array
    {
        return $this->rules[$stage->value] ?? [];
    }

    public function isCompilable(): bool
    {
        foreach ($this->rules as $stageRules) {
            foreach ($stageRules as $rule) {
                if (! $rule instanceof PredicateRule) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<array-key, PredicateRule|ActionRule> $rules */
    private function add(Stage $stage, array $rules): self
    {
        $all = $this->rules;

        foreach ($rules as $rule) {
            if (isset($all[$stage->value][$rule->id()])) {
                throw new InvalidArgumentException(sprintf(
                    'Rule id "%s" is already used in the %s stage.',
                    $rule->id(),
                    $stage->value,
                ));
            }

            $all[$stage->value][$rule->id()] = $rule;
        }

        return new self($all, $this->terminal, $this->allowsAnonymous);
    }
}
