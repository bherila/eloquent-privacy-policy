<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Decision;

/** The four rule stages, in evaluation order. The terminal is a value, not a stage of rules. */
enum Stage: string
{
    case Mandatory = 'mandatory';
    case Privileged = 'privileged';
    case Deny = 'deny';
    case Grant = 'grant';

    /** The outcome that ends evaluation when any rule of this stage returns it. */
    public function decisive(): Decision
    {
        return match ($this) {
            self::Mandatory, self::Deny => Decision::Deny,
            self::Privileged, self::Grant => Decision::Allow,
        };
    }

    public function permits(Decision $outcome): bool
    {
        return $outcome === Decision::Skip || $outcome === $this->decisive();
    }

    /** Outcome of a predicate rule for a row, given whether its predicate held. */
    public function outcomeFor(bool $predicateHeld): Decision
    {
        return match ($this) {
            // A mandatory predicate describes the boundary; failing it denies.
            self::Mandatory => $predicateHeld ? Decision::Skip : Decision::Deny,
            default => $predicateHeld ? $this->decisive() : Decision::Skip,
        };
    }
}
