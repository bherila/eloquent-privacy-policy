<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The row the racing action writes.
 *
 * @property int $id
 * @property int $space_id
 * @property int|null $owner_id
 * @property string $title
 * @property int $revision
 */
class Item extends Model
{
    use HasPrivacyPolicy;

    protected $table = Fixture::ITEMS;

    protected $guarded = [];

    public $timestamps = false;
}
