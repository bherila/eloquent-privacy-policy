<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Sql;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A raw SQL fragment the compiler assembled from fixed text and
 * grammar-wrapped, validated identifiers. Never built from a value: values
 * always travel as bindings.
 *
 * @internal
 */
final readonly class TrustedFragment implements Expression
{
    public function __construct(private string $sql)
    {
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
