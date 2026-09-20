<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * The resource boundary the concurrency fixtures anchor on.
 *
 * @property int $id
 * @property string $name
 */
class Space extends Model
{
    use HasPrivacyPolicy;

    protected $table = Fixture::SPACES;

    protected $guarded = [];

    public $timestamps = false;
}
