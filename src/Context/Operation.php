<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

use InvalidArgumentException;

/** Stable identity of the operation being performed, e.g. "record.list". */
final readonly class Operation
{
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('An operation needs a name.');
        }
    }
}
