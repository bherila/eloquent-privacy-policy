<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd;

use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;

/** Declares a model-level aggregate that would count children the viewer may not see. */
class CountingPatient extends Patient
{
    /** @var list<string> */
    protected $withCount = ['records'];
}
