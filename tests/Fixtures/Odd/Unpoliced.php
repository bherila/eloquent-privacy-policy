<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use Illuminate\Database\Eloquent\Model;

/** Adopts the trait but never declares a policy. */
class Unpoliced extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    protected $table = 'fx_unpoliced';

    protected $guarded = [];
}
