<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

/**
 * Constants shared by the seeder, the handwritten query and the benchmark
 * context so all three agree on who "the viewer" is without repeating magic
 * numbers. All synthetic: no real identifiers, no real data.
 */
final class Fixture
{
    /** How many tenants the fixture spreads notes across; the viewer's own tenant is TENANT_ID. */
    public const int TENANT_COUNT = 10;

    public const int TENANT_ID = 1;

    public const int VIEWER_ID = 1;

    /** The one clock both the context and the fixture's "expired" rows are measured against. */
    public const string NOW = '2026-06-01 12:00:00';

    /** Well before NOW, for shares that have already expired. */
    public const string PAST = '2020-01-01 00:00:00';

    /** Fraction of total notes placed in the viewer's own tenant. */
    public const float TENANT_SLICE_FRACTION = 0.10;

    /** Bucket ids (of 10, cycling over the viewer's own tenant) that a correct query must return. */
    public const array VISIBLE_BUCKETS = [0, 1, 3, 6];
}
