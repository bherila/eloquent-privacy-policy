<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;

/**
 * Resolves the facts an action's rules read — grants, memberships, quotas.
 *
 * The executor calls it inside the transaction, after the anchor locks and the
 * pre-state load, and hands it {@see LockingReads} rather than a connection:
 * a fact read here decides a write, so it must see the current committed row
 * and not a snapshot older than the anchor lock. See the LockingReads docblock
 * for why that distinction is not cosmetic.
 *
 * The returned facts are merged into the context the rules then see. They are
 * operation-local, like every other fact: nothing here is cached.
 */
interface ActionFactProvider
{
    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts;
}
