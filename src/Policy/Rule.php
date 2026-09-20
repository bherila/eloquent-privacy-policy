<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Closure;

/** Closure-backed rules. The closure of a predicate rule builds IR; it is not itself interpreted. */
final class Rule
{
    /** @param Closure(PrivacyContext): Predicate $build */
    public static function of(string $id, Closure $build): PredicateRule
    {
        return new readonly class($id, $build) implements PredicateRule {
            /** @param Closure(PrivacyContext): Predicate $build */
            public function __construct(private string $id, private Closure $build)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function predicate(PrivacyContext $context): Predicate
            {
                return ($this->build)($context);
            }
        };
    }

    /** @param Closure(PrivacyContext, ActionInput): Decision $decide */
    public static function action(string $id, Closure $decide): ActionRule
    {
        return new readonly class($id, $decide) implements ActionRule {
            /** @param Closure(PrivacyContext, ActionInput): Decision $decide */
            public function __construct(private string $id, private Closure $decide)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function decide(PrivacyContext $context, ActionInput $input): Decision
            {
                return ($this->decide)($context, $input);
            }
        };
    }
}
