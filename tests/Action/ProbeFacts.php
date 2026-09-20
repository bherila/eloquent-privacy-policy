<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use Illuminate\Support\Facades\DB;

/** Records where and how the executor called a fact provider. */
final class ProbeFacts implements ActionFactProvider
{
    public int $transactionLevel = 0;

    /** Laravel's lock flag: null when unlocked, false for a shared lock, true for FOR UPDATE. */
    public mixed $lock = null;

    public string $sql = '';

    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        $this->transactionLevel = DB::transactionLevel();

        $query = $reads->table(GrantFacts::TABLE)->where('user_id', '=', $context->viewer->id());

        // Whatever the provider does with it, the builder arrives locked.
        $this->lock = $query->lock;
        $this->sql = $query->toSql();

        return new Facts(['may_write' => $query->first(['id']) !== null]);
    }
}
