<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ActionRule;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;

/** The handful of action rules the suite reuses. */
final class ActionRules
{
    /** The persisted owner of the target is the viewer. Proposed changes are not consulted. */
    public static function ownsTarget(string $id = 'owns-target'): ActionRule
    {
        return Rule::action($id, static fn (PrivacyContext $context, ActionInput $input): Decision => self::viewerIs($input->preState, 'owner_id', $context)
            ? Decision::Allow
            : Decision::Skip);
    }

    /**
     * The viewer owns the parent the row lives in — and, when the action moves
     * it, the parent it would move to. Authorising only the parent a row is
     * leaving authorises nothing about the one it is joining.
     */
    public static function ownsBothParents(string $id = 'owns-parents'): ActionRule
    {
        return Rule::action($id, static function (PrivacyContext $context, ActionInput $input): Decision {
            $current = self::viewerIs($input->parent, 'owner_id', $context);
            $proposed = ! $input->changes('workspace_id') || self::viewerIs($input->proposedParent, 'owner_id', $context);

            return $current && $proposed ? Decision::Allow : Decision::Skip;
        });
    }

    /** A creation is allowed when the viewer owns the parent it would be created in. */
    public static function ownsCreationParent(string $id = 'owns-creation-parent'): ActionRule
    {
        return Rule::action($id, static fn (PrivacyContext $context, ActionInput $input): Decision => $input->isCreation() && self::viewerIs($input->parent, 'owner_id', $context)
            ? Decision::Allow
            : Decision::Skip);
    }

    /** Reads a fact, so a stage fails rather than skips when the fact is absent. */
    public static function hasWriteGrant(string $id = 'granted'): ActionRule
    {
        return Rule::action($id, static fn (PrivacyContext $context): Decision => $context->facts->bool('may_write') ? Decision::Allow : Decision::Skip);
    }

    /** Always returns the given outcome; the stage decides whether that is permitted. */
    public static function always(string $id, Decision $outcome): ActionRule
    {
        return Rule::action($id, static fn (): Decision => $outcome);
    }

    /** Records that it ran, so a test can prove a rule was never reached. */
    public static function tripwire(string $id, Tripwire $tripwire, Decision $outcome = Decision::Skip): ActionRule
    {
        return Rule::action($id, static function () use ($tripwire, $outcome): Decision {
            $tripwire->trip();

            return $outcome;
        });
    }

    private static function viewerIs(?RowSnapshot $row, string $column, PrivacyContext $context): bool
    {
        if ($row === null || $context->viewer->isAnonymous()) {
            return false;
        }

        return self::text($row->get($column)) === self::text($context->viewer->id());
    }

    /** Engines differ on whether an integer column comes back as int or string. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
