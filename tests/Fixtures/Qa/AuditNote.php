<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately has neither the trait nor a policy: every protected path that
 * reaches it must refuse.
 */
class AuditNote extends Model
{
    public $timestamps = false;

    protected $table = 'fx_audit_notes';

    protected $guarded = [];
}
