<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The thing actions write. Policies are registered per test rather than
 * declared here, so each test states exactly the rule set it is about.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property int|null $owner_id
 * @property string $title
 * @property bool|null $locked
 * @property int $revision
 */
class Record extends Model
{
    use HasPrivacyPolicy;

    public const string TABLE = 'act_records';

    protected $table = self::TABLE;

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
