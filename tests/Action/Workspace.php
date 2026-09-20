<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The resource boundary of the action fixtures: the row an action anchors on
 * and the parent a record belongs to. Synthetic; nothing here models anything
 * real.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property string $name
 */
class Workspace extends Model
{
    use HasPrivacyPolicy;

    public const string TABLE = 'act_workspaces';

    protected $table = self::TABLE;

    protected $guarded = [];

    public $timestamps = false;
}
